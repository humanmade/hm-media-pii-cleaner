<?php
/**
 * Sanitize-status bookkeeping for attachments: post-meta read/write, a Media
 * Library list-table column, and admin notices for flagged/failed files.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Status;

use HM\MediaPiiCleaner\Files;

/**
 * Bootstrap status-related hooks.
 */
function bootstrap() {
	add_filter( 'manage_upload_columns', __NAMESPACE__ . '\\add_media_column' );
	add_action( 'manage_media_custom_column', __NAMESPACE__ . '\\render_media_column', 10, 2 );
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_admin_notice' );
}

/**
 * Set the sanitize status (and optional detail) for an attachment.
 *
 * @param int    $attachment_id Attachment post ID.
 * @param string $status        One of the HM_MEDIA_PII_CLEANER_STATUS_* constants.
 * @param string $detail        Free-text explanation, e.g. the reason a file was flagged.
 * @param bool   $quarantine    Whether a flagged/failed status should also move the
 *                              attachment's files out of the document root. Video is the
 *                              one exception (see Video\sanitize_video_attachment()): video is
 *                              commonly used as a hardcoded <video src> literal baked directly
 *                              into saved page content (e.g. a Cover block background), not
 *                              resolved dynamically — quarantining a file still referenced that way
 *                              would 404 a live page with no graceful fallback. Video status
 *                              is recorded for admin visibility only; it never gates access.
 */
function set_status( int $attachment_id, string $status, string $detail = '', bool $quarantine = true ) : void {
	if ( $quarantine && in_array( $status, [ HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, HM_MEDIA_PII_CLEANER_STATUS_FAILED ], true ) ) {
		$quarantine_result = Files\quarantine_attachment( $attachment_id );
		$detail            .= ( '' === $detail ? '' : ' ' ) . $quarantine_result['detail'];
	}

	update_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_META, $status );

	if ( '' !== $detail ) {
		update_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_DETAIL_META, $detail );
	} else {
		delete_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_DETAIL_META );
	}
}

/**
 * Get the sanitize status for an attachment, or '' if never processed.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string
 */
function get_status( int $attachment_id ) : string {
	return (string) get_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_META, true );
}

/**
 * Get the stored detail/reason for an attachment's sanitize status.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string
 */
function get_detail( int $attachment_id ) : string {
	return (string) get_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_DETAIL_META, true );
}

/**
 * Is this attachment's current status one that means it's safe to expose publicly?
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool
 */
function is_sanitized( int $attachment_id ) : bool {
	return HM_MEDIA_PII_CLEANER_STATUS_SANITIZED === get_status( $attachment_id );
}

/**
 * Add a "Metadata" column to the Media Library list table.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function add_media_column( array $columns ) : array {
	$columns['hm_media_pii_cleaner_status'] = __( 'Metadata', 'hm-media-pii-cleaner' );
	return $columns;
}

/**
 * Render the "Metadata" column for a given attachment.
 *
 * @param string $column_name Column being rendered.
 * @param int    $attachment_id Attachment post ID.
 */
function render_media_column( string $column_name, int $attachment_id ) : void {
	if ( 'hm_media_pii_cleaner_status' !== $column_name ) {
		return;
	}

	$status = get_status( $attachment_id );

	if ( '' === $status ) {
		echo '<span style="color:#999;">' . esc_html__( 'Not processed', 'hm-media-pii-cleaner' ) . '</span>';
		return;
	}

	$labels = [
		HM_MEDIA_PII_CLEANER_STATUS_SANITIZED => [ '#046B2B', __( 'Sanitized', 'hm-media-pii-cleaner' ) ],
		HM_MEDIA_PII_CLEANER_STATUS_FLAGGED   => [ '#B32D2E', __( 'Flagged for review', 'hm-media-pii-cleaner' ) ],
		HM_MEDIA_PII_CLEANER_STATUS_FAILED    => [ '#B32D2E', __( 'Failed', 'hm-media-pii-cleaner' ) ],
	];

	[ $color, $label ] = $labels[ $status ] ?? [ '#999', $status ];

	printf(
		'<span style="color:%1$s;font-weight:600;" title="%2$s">%3$s</span>',
		esc_attr( $color ),
		esc_attr( get_detail( $attachment_id ) ),
		esc_html( $label )
	);
}

/**
 * Show a persistent admin notice enumerating flagged/failed attachments.
 */
function render_admin_notice() : void {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}

	$flagged = get_posts(
		[
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 20,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, capped (posts_per_page: 20) admin-notice query, not a hot path.
			'meta_query'     => [
				[
					'key'     => HM_MEDIA_PII_CLEANER_STATUS_META,
					'value'   => [ HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, HM_MEDIA_PII_CLEANER_STATUS_FAILED ],
					'compare' => 'IN', // phpcs:ignore HM.Performance.SlowMetaQuery.nonperformant_comparison -- small, capped (posts_per_page: 20) admin-notice query, not a hot path.
				],
			],
			'fields'         => 'ids',
		]
	);

	if ( empty( $flagged ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	printf(
		/* translators: %d: number of flagged attachments. */
		esc_html( _n(
			'%d file could not be automatically sanitized of hidden metadata and needs manual review.',
			'%d files could not be automatically sanitized of hidden metadata and need manual review.',
			count( $flagged ),
			'hm-media-pii-cleaner'
		) ),
		count( $flagged )
	);
	echo ' <a href="' . esc_url( admin_url( 'upload.php' ) ) . '">' . esc_html__( 'Review in Media Library', 'hm-media-pii-cleaner' ) . '</a>';
	echo '</p></div>';
}
