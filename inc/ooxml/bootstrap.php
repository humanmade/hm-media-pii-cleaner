<?php
/**
 * OOXML (DOCX/XLSX/PPTX) attachment sanitization.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Ooxml;

use HM\MediaPiiCleaner\Status;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Limits;

/**
 * Sanitize an OOXML attachment's original file and any generated sub-size.
 * OOXML documents have no WordPress-generated sub-sizes in practice, but
 * this is handled generically for consistency with every other format here.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 */
function sanitize_ooxml_attachment( int $attachment_id, array $metadata ) : void {
	$paths          = Files\attachment_file_paths( $attachment_id, $metadata );
	$unreadable     = [];
	$total_stripped = 0;
	$mime           = HM_MEDIA_PII_CLEANER_OOXML_MIMES[0];

	foreach ( $paths as $path ) {
		if ( ! is_readable( $path ) ) {
			$unreadable[] = $path;
			continue;
		}

		try {
			$original  = Limits\read_file( $path, $mime );
			$result    = strip_ooxml_metadata( $original );
			$rechecked = strip_ooxml_metadata( $result['data'] );
		} catch ( \RuntimeException $e ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "OOXML parse error on $path: " . $e->getMessage() );
			return;
		}

		if ( $rechecked['data'] !== $result['data'] ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "OOXML metadata sanitizer was not idempotent on $path." );
			return;
		}

		if ( $result['stripped_parts'] > 0 ) {
			if ( ! Files\write_atomically( $path, $result['data'] ) ) {
				Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "OOXML metadata could not be safely written to $path." );
				return;
			}
			$total_stripped += $result['stripped_parts'];
		}
	}

	if ( [] !== $unreadable ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'OOXML sanitization incomplete — could not read: ' . implode( ', ', $unreadable ) );
		return;
	}

	Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_SANITIZED, "Stripped $total_stripped OOXML metadata part(s)." );
}
