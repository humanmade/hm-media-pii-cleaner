<?php
/**
 * Upload-time dispatch: routes newly-generated attachment metadata to the
 * per-MIME-type sanitizer, and rejects obviously-unsafe uploads up front.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Dispatcher;

use HM\MediaPiiCleaner\Images;
use HM\MediaPiiCleaner\Pdf;
use HM\MediaPiiCleaner\Video;
use HM\MediaPiiCleaner\Ooxml;
use HM\MediaPiiCleaner\Limits;

/**
 * Bootstrap dispatcher hooks.
 */
function bootstrap() {
	add_filter( 'wp_handle_upload_prefilter', __NAMESPACE__ . '\\reject_unsafe_upload' );
	// EWWW hooks this filter at priorities 8 and 15. Sanitize supported images
	// before optimization, then run the normal idempotent pass afterward to
	// catch anything an optimizer introduced or rewrote.
	add_filter( 'wp_generate_attachment_metadata', __NAMESPACE__ . '\\sanitize_images_before_ewww', 7, 2 );
	add_filter( 'wp_generate_attachment_metadata', __NAMESPACE__ . '\\sanitize_and_verify', 20, 2 );
}

/**
 * Sanitize image formats EWWW processes before its upload-time callbacks.
 * PDFs and formats EWWW does not optimize remain on the priority-20 pass.
 *
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 * @param int   $attachment_id Attachment post ID.
 * @return array
 */
function sanitize_images_before_ewww( array $metadata, int $attachment_id ) : array {
	$mime = get_post_mime_type( $attachment_id );

	if ( in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
		return sanitize_and_verify( $metadata, $attachment_id );
	}

	return $metadata;
}

/**
 * Cheap pre-attachment reject for files this plugin can't safely handle at all.
 *
 * This runs before an attachment exists, so no sanitization happens here —
 * only a hard reject for structurally unsafe uploads (e.g. unparseable PDFs).
 *
 * @param array $file Upload data, per wp_handle_upload_prefilter.
 * @return array
 */
function reject_unsafe_upload( array $file ) : array {
	$mime = (string) ( $file['type'] ?? '' );

	if ( Limits\is_covered_mime( $mime ) ) {
		$size = false;
		if ( isset( $file['tmp_name'] ) && is_file( $file['tmp_name'] ) ) {
			clearstatcache( true, $file['tmp_name'] );
			$size = filesize( $file['tmp_name'] );
		} elseif ( isset( $file['size'] ) && is_numeric( $file['size'] ) ) {
			$size = (int) $file['size'];
		}

		if ( false !== $size ) {
			$error = Limits\size_error( $size, $mime );
			if ( '' !== $error ) {
				$file['error'] = sprintf(
					/* translators: %s: explanation of the active resource limit. */
					__( 'This upload cannot be safely sanitized: %s', 'hm-media-pii-cleaner' ),
					$error
				);
				return $file;
			}
		}
	}

	if ( HM_MEDIA_PII_CLEANER_PDF_MIME !== $mime ) {
		return $file;
	}

	if ( ! is_readable( $file['tmp_name'] ) ) {
		return $file;
	}

	$header = file_get_contents( $file['tmp_name'], false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( '%PDF-' !== $header ) {
		$file['error'] = __( 'This does not appear to be a valid PDF file.', 'hm-media-pii-cleaner' );
	}

	return $file;
}

/**
 * Route a newly-generated attachment's metadata to the matching sanitizer.
 *
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 * @param int   $attachment_id Attachment post ID.
 * @return array
 */
function sanitize_and_verify( array $metadata, int $attachment_id ) : array {
	$metadata = Images\filter_attachment_metadata( $metadata );
	$mime = get_post_mime_type( $attachment_id );

	switch ( $mime ) {
		case 'image/jpeg':
			Images\sanitize_jpeg_attachment( $attachment_id, $metadata );
			break;

		case 'image/png':
			Images\sanitize_png_attachment( $attachment_id, $metadata );
			break;

		case 'image/webp':
			Images\sanitize_webp_attachment( $attachment_id, $metadata );
			break;

		case 'image/gif':
			Images\sanitize_gif_attachment( $attachment_id, $metadata );
			break;

		case 'image/svg+xml':
			Images\sanitize_svg_attachment( $attachment_id );
			break;

		case HM_MEDIA_PII_CLEANER_PDF_MIME:
			Pdf\sanitize_attachment( $attachment_id, false, $metadata );
			break;

		case 'video/mp4':
		case 'video/quicktime':
			Video\sanitize_video_attachment( $attachment_id, $metadata );
			break;

		case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
		case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
		case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
			Ooxml\sanitize_ooxml_attachment( $attachment_id, $metadata );
			break;
	}

	return $metadata;
}
