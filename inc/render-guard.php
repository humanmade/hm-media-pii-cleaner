<?php
/**
 * Public API used by other plugins to check whether an attachment is safe to
 * expose, without hard-depending on this plugin being active.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\RenderGuard;

use HM\MediaPiiCleaner\Status;

/**
 * Bootstrap render-guard hooks.
 */
function bootstrap() {
	add_filter( 'wp_get_attachment_url', __NAMESPACE__ . '\\filter_attachment_url', 999, 2 );
}

/**
 * Do not expose an attachment URL through WordPress APIs after it has been
 * flagged. Flagged files are also moved out of uploads by the status layer;
 * this filter prevents API consumers from retaining a stale URL.
 *
 * @param string $url Attachment URL.
 * @param int    $attachment_id Attachment post ID.
 * @return string
 */
function filter_attachment_url( string $url, int $attachment_id ) : string {
	return is_attachment_sanitized( $attachment_id ) ? $url : '';
}

/**
 * Is this attachment safe to expose via a public download link?
 *
 * Only covered MIME types (see constants.php) are ever gated — attachments
 * this plugin doesn't process (e.g. non-PDF documents) are treated as
 * already safe, since there's nothing here to have flagged them.
 *
 * Video is deliberately NOT in the gated set, unlike every other covered
 * format. Video is commonly used as a hardcoded <video src> literal baked
 * directly into saved page content (e.g. a Cover block background), not
 * resolved dynamically through wp_get_attachment_url() —
 * so this filter would never even run for it in the case that actually
 * matters, while still creating a false sense that gating is enforced. Video
 * status is tracked for admin visibility only; see Video\sanitize_video_attachment().
 *
 * Fails OPEN for never-processed attachments (no status meta at all) rather
 * than treating "not yet scanned" the same as "flagged" — every attachment
 * that existed before this plugin was activated falls in that bucket on day
 * one, and existing download links must keep working. Only an explicit flagged/failed verdict
 * blocks exposure; run `wp hm-media-pii-cleaner status-report` to find
 * never-processed attachments and `sanitize-pdfs --all` /
 * `verify-images --all` / etc. to clear the backlog.
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool
 */
function is_attachment_sanitized( int $attachment_id ) : bool {
	$mime = get_post_mime_type( $attachment_id );

	$covered_mimes = [
		...HM_MEDIA_PII_CLEANER_IMAGE_MIMES,
		HM_MEDIA_PII_CLEANER_PDF_MIME,
		...HM_MEDIA_PII_CLEANER_OOXML_MIMES,
	];

	if ( ! in_array( $mime, $covered_mimes, true ) ) {
		return true;
	}

	$status = Status\get_status( $attachment_id );

	return ! in_array( $status, [ HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, HM_MEDIA_PII_CLEANER_STATUS_FAILED ], true );
}
