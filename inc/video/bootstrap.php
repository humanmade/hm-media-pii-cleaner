<?php
/**
 * Video (MP4/QuickTime) attachment sanitization.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Video;

use HM\MediaPiiCleaner\Status;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Limits;

/**
 * Sanitize a video attachment's original file and any generated sub-size.
 * Video attachments have no WordPress-generated sub-sizes in practice, but
 * this is handled generically for consistency with every other format here.
 *
 * Every flagged status here passes $quarantine = false: unlike every other
 * covered format, video is commonly used as a hardcoded <video src> literal
 * baked directly into saved page content (e.g. a Cover block background),
 * not resolved dynamically through
 * wp_get_attachment_url() — quarantining a file still referenced that way
 * would 404 a live page with no graceful fallback. A flagged video's status
 * is recorded for admin visibility only (status-report, admin notice); it
 * never gates access or moves the file. See Status\set_status() and
 * LIMITATIONS.md.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 */
function sanitize_video_attachment( int $attachment_id, array $metadata ) : void {
	$paths          = Files\attachment_file_paths( $attachment_id, $metadata );
	$unreadable     = [];
	$total_stripped = 0;
	$mime           = HM_MEDIA_PII_CLEANER_VIDEO_MIMES[0];

	foreach ( $paths as $path ) {
		if ( ! is_readable( $path ) ) {
			$unreadable[] = $path;
			continue;
		}

		try {
			$original  = Limits\read_file( $path, $mime );
			$result    = strip_video_metadata( $original );
			$rechecked = strip_video_metadata( $result['data'] );
		} catch ( \RuntimeException $e ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "Video parse error on $path: " . $e->getMessage(), false );
			return;
		}

		if ( $rechecked['data'] !== $result['data'] ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "Video metadata sanitizer was not idempotent on $path.", false );
			return;
		}

		if ( $result['stripped_boxes'] > 0 ) {
			if ( ! Files\write_atomically( $path, $result['data'] ) ) {
				Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "Video metadata could not be safely written to $path.", false );
				return;
			}
			$total_stripped += $result['stripped_boxes'];
		}
	}

	if ( [] !== $unreadable ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'Video sanitization incomplete — could not read: ' . implode( ', ', $unreadable ), false );
		return;
	}

	Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_SANITIZED, "Stripped $total_stripped video metadata box(es)." );
}
