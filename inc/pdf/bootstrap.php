<?php
/**
 * PDF sanitization orchestration: runs Stage 1 (rebuild) + Stage 2 (metadata-
 * stream stripping) + the verification gate, and records the outcome.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Pdf;

use HM\MediaPiiCleaner\Status;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Images;
use HM\MediaPiiCleaner\Limits;

/**
 * Bootstrap PDF-sanitization hooks. Currently a no-op — the dispatcher calls
 * sanitize_attachment() directly — reserved for future hook wiring.
 */
function bootstrap() {}

/**
 * Sanitize a PDF attachment in place: Stage 1 + Stage 2 + verify, replacing
 * the file at its existing path only if verification passes.
 *
 * On failure, the attachment is flagged and its public files are quarantined
 * by Status\set_status(), preventing the original URL from remaining live.
 *
 * @param int        $attachment_id Attachment post ID.
 * @param bool       $dry_run       If true, compute and return the outcome without
 *                                  writing to disk or persisting status meta.
 * @param array|null $metadata      Attachment metadata containing generated JPEG previews.
 * @return array{ok: bool, reason: string} Outcome, also recorded to post-meta
 *                                          (unless $dry_run).
 */
function sanitize_attachment( int $attachment_id, bool $dry_run = false, ?array $metadata = null ) : array {
	$path = get_attached_file( $attachment_id );

	if ( ! $path || ! is_readable( $path ) ) {
		return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'Attachment file not found or not readable.', false );
	}
	$metadata ??= wp_get_attachment_metadata( $attachment_id ) ?: [];

	try {
		Limits\assert_file_processable( $path, HM_MEDIA_PII_CLEANER_PDF_MIME );
		$stage1 = rebuild_pdf( $path );
		Limits\assert_data_processable( $stage1['data'], HM_MEDIA_PII_CLEANER_PDF_MIME );
	} catch ( \RuntimeException $e ) {
		return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'Stage 1 (rebuild) failed: ' . $e->getMessage(), false );
	}

	try {
		$stage2 = strip_metadata_streams( $stage1['data'] );
	} catch ( \RuntimeException $e ) {
		return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'Stage 2 (metadata-stream stripping) failed: ' . $e->getMessage(), false );
	}

	$verification = verify( $path, $stage1['data'], $stage2['data'] );

	if ( ! $verification['ok'] ) {
		return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $verification['reason'], false );
	}

	$detail = sprintf(
		'Stage 2 stripped %d metadata stream(s); %d skipped (compressed, unhandled).',
		$stage2['stripped_count'],
		$stage2['skipped_filtered_count']
	);

	if ( $stage2['skipped_filtered_count'] > 0 ) {
		return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $detail . ' Compressed metadata streams are not supported by Stage 2.', false );
	}

	if ( ! $dry_run ) {
		if ( ! write_file( $path, $stage2['data'] ) ) {
			return finish( $attachment_id, false, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'Sanitized PDF could not be safely written.', false );
		}
	}

	$preview_result = Images\sanitize_pdf_preview_images( $attachment_id, $metadata, $dry_run );
	if ( ! $preview_result['ok'] ) {
		if ( ! $dry_run ) {
			$removed = Images\remove_pdf_preview_images( $attachment_id, $metadata );
			$preview_result['detail'] .= $removed
				? ' Generated preview files were removed.'
				: ' One or more unsafe preview files could not be removed.';
		}
		return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $preview_result['detail'], false );
	}
	$detail .= ' ' . $preview_result['detail'];

	return finish( $attachment_id, $dry_run, HM_MEDIA_PII_CLEANER_STATUS_SANITIZED, $detail, true );
}

/**
 * Write sanitized PDF bytes atomically and verify the committed replacement.
 *
 * @param string $path Destination path.
 * @param string $data Bytes to write.
 */
function write_file( string $path, string $data ) : bool {
	return Files\write_atomically( $path, $data );
}

/**
 * Persist (unless dry-run) and return a sanitize_attachment() outcome.
 *
 * @param int    $attachment_id Attachment post ID.
 * @param bool   $dry_run       Whether to skip persisting status meta.
 * @param string $status        Status constant to record.
 * @param string $reason        Detail/reason text.
 * @param bool   $ok            Whether this counts as an overall success.
 * @return array{ok: bool, reason: string}
 */
function finish( int $attachment_id, bool $dry_run, string $status, string $reason, bool $ok ) : array {
	if ( ! $dry_run ) {
		Status\set_status( $attachment_id, $status, $reason );
	}
	return [
		'ok' => $ok,
		'reason' => $reason,
	];
}
