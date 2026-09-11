<?php
/**
 * Helper functions for the `hm-media-pii-cleaner` WP-CLI command, plus its
 * registration with WP-CLI.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Cli;

use HM\MediaPiiCleaner\Status;

/**
 * Register the `wp hm-media-pii-cleaner` command with WP-CLI.
 */
function bootstrap() {
	\WP_CLI::add_command( 'hm-media-pii-cleaner', __NAMESPACE__ . '\\Command' );
}

/**
 * Walk attachments in bounded batches, advancing by ID rather than offset so
 * concurrent deletions cannot shift unprocessed attachments into earlier pages.
 *
 * @param array        $args       Positional args (specific IDs).
 * @param array        $assoc_args Flags (--all, --force).
 * @param string|array $mime_types MIME type(s) to filter by when --all is used.
 * @param callable     $callback   Called with each attachment's WP_Post.
 * @param Results|null $results    Optional tally for invalid explicit IDs.
 */
function walk_attachments( array $args, array $assoc_args, $mime_types, callable $callback, ?Results $results = null ) : void {
	global $wpdb;

	if ( ! empty( $args ) ) {
		foreach ( array_chunk( array_unique( $args ), HM_MEDIA_PII_CLEANER_CLI_BATCH_SIZE ) as $batch ) {
			foreach ( $batch as $requested_id ) {
				$id = filter_var( $requested_id, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
				$attachment = false !== $id ? get_post( $id ) : null;
				$reason = '';
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					$reason = 'Not a valid attachment ID.';
				} elseif ( ! in_array( $attachment->post_mime_type, (array) $mime_types, true ) ) {
					$reason = 'Attachment MIME type does not match this command: ' . $attachment->post_mime_type;
				}
				if ( '' !== $reason ) {
					\WP_CLI::warning( sprintf( '#%s: %s', $requested_id, $reason ) );
					if ( null !== $results ) {
						$results->add( [
							'attachment_id' => $requested_id,
							'file'          => '',
							'ok'            => 'no',
							'reason'        => $reason,
						], false );
					}
					continue;
				}

				try {
					$callback( $attachment );
				} finally {
					clean_attachment_cache( $attachment->ID );
				}
			}
			$wpdb->queries = [];
		}
		return;
	}

	if ( ! isset( $assoc_args['all'] ) ) {
		\WP_CLI::error( 'Specify one or more attachment IDs, or pass --all.' );
	}

	$query_args = [
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => $mime_types,
		'posts_per_page'         => HM_MEDIA_PII_CLEANER_CLI_BATCH_SIZE,
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'cache_results'          => false,
		'suppress_filters'       => false,
	];
	$after_id = 0;
	$after = static function ( string $where, $query ) use ( &$after_id, $wpdb ) : string {
		if ( true !== $query->get( 'hm_media_pii_cleaner_walk' ) ) {
			return $where;
		}
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
	};
	$query_args['hm_media_pii_cleaner_walk'] = true;

	do {
		add_filter( 'posts_where', $after, 10, 2 );
		try {
			$attachments = get_posts( $query_args );
		} finally {
			remove_filter( 'posts_where', $after, 10 );
		}

		try {
			foreach ( $attachments as $attachment ) {
				$after_id = $attachment->ID;
				if ( empty( $assoc_args['force'] ) && Status\is_sanitized( $attachment->ID ) ) {
					continue;
				}
				$callback( $attachment );
			}
		} finally {
			// Evict only this batch, leaving the site's shared cache intact.
			foreach ( $attachments as $attachment ) {
				clean_attachment_cache( $attachment->ID );
			}
			$wpdb->queries = [];
		}
	} while ( count( $attachments ) === HM_MEDIA_PII_CLEANER_CLI_BATCH_SIZE );
}

/**
 * Close the report and print a pass/fail summary.
 *
 * @param Results $results    Tally of the run.
 * @param bool    $dry_run    Whether this was a dry run.
 * @param string  $none_found Warning shown when nothing matched.
 */
function summarize( Results $results, bool $dry_run, string $none_found ) : void {
	$wrote_report = $results->finish();

	if ( 0 === $results->total() ) {
		\WP_CLI::warning( $none_found );
		return;
	}

	\WP_CLI::log( '' );
	\WP_CLI::log( sprintf( '%d/%d %s.', $results->passed(), $results->total(), $dry_run ? 'would be sanitized' : 'sanitized' ) );

	if ( $wrote_report ) {
		\WP_CLI::success( 'Wrote report to ' . $results->path() );
	}
}
