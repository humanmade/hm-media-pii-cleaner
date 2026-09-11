<?php
/**
 * WP-CLI commands for auditing and remediating existing attachments:
 *
 *   wp hm-media-pii-cleaner sanitize-all   [--include-videos] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner sanitize-pdfs  [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner sanitize-gifs  [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner sanitize-svgs  [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner sanitize-videos [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner sanitize-ooxml [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner verify-images  [<id>...] [--all] [--force] [--dry-run] [--report=<path>]
 *   wp hm-media-pii-cleaner set-status <id> <status> [--detail=<text>]
 *   wp hm-media-pii-cleaner status-report [--format=<all|pdf|image|video|ooxml>] [--output=<path>]
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Cli;

use HM\MediaPiiCleaner\Status;
use HM\MediaPiiCleaner\Images;
use HM\MediaPiiCleaner\Pdf;
use HM\MediaPiiCleaner\Video;
use HM\MediaPiiCleaner\Ooxml;
use HM\MediaPiiCleaner\Limits;

/**
 * `wp hm-media-pii-cleaner ...`
 */
class Command extends \WP_CLI_Command {

	/**
	 * Run every sanitize subcommand over its whole media type in one pass,
	 * with a single summary and report: PDF, GIF, SVG, OOXML, then
	 * JPEG/PNG/WebP last so it verifies what EWWW left behind.
	 *
	 * Video is left out unless asked for: most fast-start videos are flagged
	 * rather than fixed, so a video run is a deliberate call, not part of a
	 * routine backfill. See LIMITATIONS.md.
	 *
	 * ## OPTIONS
	 *
	 * [--include-videos]
	 * : Also process MP4/QuickTime attachments, last.
	 *
	 * [--force]
	 * : Also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report the outcome per file without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write one CSV summary covering every media type to this path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hm-media-pii-cleaner sanitize-all --dry-run --report=/tmp/sanitize-all.csv
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @subcommand sanitize-all
	 */
	public function sanitize_all( $args, $assoc_args ) {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$results    = new Results( $assoc_args['report'] ?? '' );
		$assoc_args = [ 'all' => true ] + $assoc_args;

		$types = [
			'PDF'           => 'walk_pdfs',
			'GIF'           => 'walk_gifs',
			'SVG'           => 'walk_svgs',
			'OOXML'         => 'walk_ooxml',
			'JPEG/PNG/WebP' => 'walk_images',
		];

		if ( isset( $assoc_args['include-videos'] ) ) {
			$types['Video'] = 'walk_videos';
		}

		foreach ( $types as $label => $method ) {
			\WP_CLI::log( sprintf( '== %s', $label ) );
			$this->$method( [], $assoc_args, $dry_run, $results );
		}

		summarize( $results, $dry_run, 'No matching attachments found.' );
	}

	/**
	 * Sanitize PDF attachments (Stage 1 + Stage 2 + verification).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more specific attachment IDs.
	 *
	 * [--all]
	 * : Process every application/pdf attachment currently missing a
	 * "sanitized" status.
	 *
	 * [--force]
	 * : With --all, also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report the outcome per file without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write a CSV summary to this path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand sanitize-pdfs
	 */
	public function sanitize_pdfs( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = new Results( $assoc_args['report'] ?? '' );

		$this->walk_pdfs( $args, $assoc_args, $dry_run, $results );

		summarize( $results, $dry_run, 'No matching PDF attachments found.' );
	}

	/**
	 * Re-verify JPEG/PNG/WebP attachments (top up with a GD re-encode where
	 * EWWW's local-binary path can't be confirmed functional).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more specific attachment IDs.
	 *
	 * [--all]
	 * : Process every JPEG/PNG/WebP attachment currently missing a "sanitized" status.
	 *
	 * [--force]
	 * : With --all, also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write a CSV summary to this path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand verify-images
	 */
	public function verify_images( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = new Results( $assoc_args['report'] ?? '' );

		$this->walk_images( $args, $assoc_args, $dry_run, $results );

		summarize( $results, $dry_run, 'No matching image attachments found.' );
	}

	/**
	 * Strip GIF Comment/foreign-Application extensions from GIF attachments.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more specific attachment IDs.
	 *
	 * [--all]
	 * : Process every image/gif attachment currently missing a "sanitized" status.
	 *
	 * [--force]
	 * : With --all, also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write a CSV summary to this path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand sanitize-gifs
	 */
	public function sanitize_gifs( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = new Results( $assoc_args['report'] ?? '' );

		$this->walk_gifs( $args, $assoc_args, $dry_run, $results );

		summarize( $results, $dry_run, 'No matching GIF attachments found.' );
	}

	/**
	 * Strip metadata, comments, and editor attributes from SVG attachments while preserving title/desc accessibility content.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more specific attachment IDs.
	 *
	 * [--all]
	 * : Process every image/svg+xml attachment currently missing a "sanitized" status.
	 *
	 * [--force]
	 * : With --all, also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write a CSV summary to this path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand sanitize-svgs
	 */
	public function sanitize_svgs( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = new Results( $assoc_args['report'] ?? '' );

		$this->walk_svgs( $args, $assoc_args, $dry_run, $results );

		summarize( $results, $dry_run, 'No matching SVG attachments found.' );
	}

	/**
	 * Strip udta/meta authoring metadata from MP4/QuickTime video attachments.
	 * Fast-start files (moov before mdat) are flagged rather than risked —
	 * see LIMITATIONS.md. Flagged videos are never quarantined or blocked
	 * from resolving their URL — see Video\sanitize_video_attachment().
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more specific attachment IDs.
	 *
	 * [--all]
	 * : Process every video/mp4 or video/quicktime attachment currently missing a "sanitized" status.
	 *
	 * [--force]
	 * : With --all, also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write a CSV summary to this path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand sanitize-videos
	 */
	public function sanitize_videos( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = new Results( $assoc_args['report'] ?? '' );

		$this->walk_videos( $args, $assoc_args, $dry_run, $results );

		summarize( $results, $dry_run, 'No matching video attachments found.' );
	}

	/**
	 * Filter docProps/core.xml and docProps/app.xml down to the
	 * allowlist on DOCX/XLSX/PPTX attachments, and empty docProps/custom.xml.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more specific attachment IDs.
	 *
	 * [--all]
	 * : Process every OOXML attachment currently missing a "sanitized" status.
	 *
	 * [--force]
	 * : With --all, also re-process attachments already marked "sanitized".
	 *
	 * [--dry-run]
	 * : Report without writing to disk or persisting status.
	 *
	 * [--report=<path>]
	 * : Write a CSV summary to this path.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand sanitize-ooxml
	 */
	public function sanitize_ooxml( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = new Results( $assoc_args['report'] ?? '' );

		$this->walk_ooxml( $args, $assoc_args, $dry_run, $results );

		summarize( $results, $dry_run, 'No matching OOXML attachments found.' );
	}

	/**
	 * Manually set an attachment's sanitize status (e.g. after an off-host
	 * exiftool remediation).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * <status>
	 * : One of: sanitized, flagged, failed.
	 *
	 * [--detail=<text>]
	 * : Free-text reason/detail.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand set-status
	 */
	public function set_status( $args, $assoc_args ) {
		[ $id, $status ] = $args;

		$valid = [ HM_MEDIA_PII_CLEANER_STATUS_SANITIZED, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, HM_MEDIA_PII_CLEANER_STATUS_FAILED ];
		if ( ! in_array( $status, $valid, true ) ) {
			\WP_CLI::error( 'Status must be one of: ' . implode( ', ', $valid ) );
		}

		Status\set_status( (int) $id, $status, $assoc_args['detail'] ?? '' );
		\WP_CLI::success( "Set attachment #$id status to $status." );
	}

	/**
	 * Read-only report of every covered attachment's sanitize status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<all|pdf|image|video|ooxml>]
	 * : Which MIME group to report on. Default: all.
	 *
	 * [--output=<path>]
	 * : Write a CSV to this path instead of just logging a summary.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @subcommand status-report
	 */
	public function status_report( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'all';
		$mimes  = match ( $format ) {
			'pdf'   => [ HM_MEDIA_PII_CLEANER_PDF_MIME ],
			'image' => HM_MEDIA_PII_CLEANER_IMAGE_MIMES,
			'video' => HM_MEDIA_PII_CLEANER_VIDEO_MIMES,
			'ooxml' => HM_MEDIA_PII_CLEANER_OOXML_MIMES,
			default => [
				...HM_MEDIA_PII_CLEANER_IMAGE_MIMES,
				HM_MEDIA_PII_CLEANER_PDF_MIME,
				...HM_MEDIA_PII_CLEANER_VIDEO_MIMES,
				...HM_MEDIA_PII_CLEANER_OOXML_MIMES,
			],
		};

		$results = new Results( $assoc_args['output'] ?? '' );
		$counts  = [];

		// --force so already-sanitized attachments are reported too.
		walk_attachments(
			[],
			[
				'all'   => true,
				'force' => true,
			],
			$mimes,
			static function ( \WP_Post $attachment ) use ( $results, &$counts ) : void {
				$id                = $attachment->ID;
				$status            = Status\get_status( $id ) ?: 'never_processed';
				$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
				$results->add(
					[
						'attachment_id' => $id,
						'mime'          => $attachment->post_mime_type,
						'file'          => get_attached_file( $id ),
						'status'        => $status,
						'detail'        => Status\get_detail( $id ),
					]
				);
			},
			$results
		);

		foreach ( $counts as $status => $count ) {
			\WP_CLI::log( sprintf( '%-18s %d', $status, $count ) );
		}

		if ( $results->finish() ) {
			\WP_CLI::success( 'Wrote report to ' . $results->path() );
		}
	}

	/**
	 * Process PDF attachments into a shared tally.
	 *
	 * @param array   $args       Positional args (specific IDs).
	 * @param array   $assoc_args Flags.
	 * @param bool    $dry_run    Whether to leave files and status untouched.
	 * @param Results $results    Tally the rows are added to.
	 */
	private function walk_pdfs( array $args, array $assoc_args, bool $dry_run, Results $results ) : void {
		walk_attachments(
			$args,
			$assoc_args,
			HM_MEDIA_PII_CLEANER_PDF_MIME,
			static function ( \WP_Post $attachment ) use ( $dry_run, $results ) : void {
				$id     = $attachment->ID;
				$result = Pdf\sanitize_attachment( $id, $dry_run );
				$results->add(
					[
						'attachment_id' => $id,
						'file'          => get_attached_file( $id ),
						'ok'            => $result['ok'] ? 'yes' : 'no',
						'reason'        => $result['reason'],
					],
					$result['ok']
				);
				\WP_CLI::log( sprintf( '#%d: %s — %s', $id, $result['ok'] ? 'sanitized' : 'flagged', $result['reason'] ) );
			},
			$results
		);
	}

	/**
	 * Process JPEG/PNG/WebP attachments into a shared tally.
	 *
	 * @param array   $args       Positional args (specific IDs).
	 * @param array   $assoc_args Flags.
	 * @param bool    $dry_run    Whether to leave files and status untouched.
	 * @param Results $results    Tally the rows are added to.
	 */
	private function walk_images( array $args, array $assoc_args, bool $dry_run, Results $results ) : void {
		walk_attachments(
			$args,
			$assoc_args,
			[ 'image/jpeg', 'image/png', 'image/webp' ],
			static function ( \WP_Post $attachment ) use ( $dry_run, $results ) : void {
				$id   = $attachment->ID;
				$mime = $attachment->post_mime_type;
				if ( $dry_run ) {
					$result = Images\inspect_chunked_image_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] );
					$status = $result['ok'] ? 'would sanitize' : 'would flag';
					$ok     = $result['ok'];
					$reason = $result['detail'];
				} else {
					Images\sanitize_stored_image_metadata( $id );
					match ( $mime ) {
						'image/jpeg' => Images\sanitize_jpeg_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] ),
						'image/png'  => Images\sanitize_png_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] ),
						'image/webp' => Images\sanitize_webp_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] ),
						default      => null,
					};
					$status = Status\get_status( $id );
					$ok     = 'sanitized' === $status;
					$reason = $status;
				}
				$results->add(
					[
						'attachment_id' => $id,
						'file'          => get_attached_file( $id ),
						'ok'            => $ok ? 'yes' : 'no',
						'reason'        => $reason,
					],
					$ok
				);
				\WP_CLI::log( sprintf( '#%d (%s): %s — %s', $id, $mime, $status, $reason ) );
			},
			$results
		);
	}

	/**
	 * Process GIF attachments into a shared tally.
	 *
	 * @param array   $args       Positional args (specific IDs).
	 * @param array   $assoc_args Flags.
	 * @param bool    $dry_run    Whether to leave files and status untouched.
	 * @param Results $results    Tally the rows are added to.
	 */
	private function walk_gifs( array $args, array $assoc_args, bool $dry_run, Results $results ) : void {
		walk_attachments(
			$args,
			$assoc_args,
			'image/gif',
			static function ( \WP_Post $attachment ) use ( $dry_run, $results ) : void {
				$id = $attachment->ID;
				if ( $dry_run ) {
					$path = get_attached_file( $id );
					if ( ! $path || ! is_readable( $path ) ) {
						$outcome = 'would flag: Attachment file not found or not readable.';
						$ok      = false;
					} else {
						try {
							$result  = Images\strip_gif_metadata( Limits\read_file( $path, 'image/gif' ) );
							$outcome = 'would strip ' . $result['stripped_blocks'] . ' block(s)';
							$ok      = true;
						} catch ( \RuntimeException $e ) {
							$outcome = 'would flag: ' . $e->getMessage();
							$ok      = false;
						}
					}
				} else {
					Images\sanitize_stored_image_metadata( $id );
					Images\sanitize_gif_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] );
					$outcome = Status\get_detail( $id );
					$ok      = Status\is_sanitized( $id );
				}

				$results->add(
					[
						'attachment_id' => $id,
						'file'          => get_attached_file( $id ),
						'ok'            => $ok ? 'yes' : 'no',
						'reason'        => $outcome,
					],
					$ok
				);
				\WP_CLI::log( sprintf( '#%d: %s', $id, $outcome ) );
			},
			$results
		);
	}

	/**
	 * Process SVG attachments into a shared tally.
	 *
	 * @param array   $args       Positional args (specific IDs).
	 * @param array   $assoc_args Flags.
	 * @param bool    $dry_run    Whether to leave files and status untouched.
	 * @param Results $results    Tally the rows are added to.
	 */
	private function walk_svgs( array $args, array $assoc_args, bool $dry_run, Results $results ) : void {
		walk_attachments(
			$args,
			$assoc_args,
			'image/svg+xml',
			static function ( \WP_Post $attachment ) use ( $dry_run, $results ) : void {
				$id = $attachment->ID;
				if ( $dry_run ) {
					$path = get_attached_file( $id );
					if ( ! $path || ! is_readable( $path ) ) {
						$outcome = 'would flag: Attachment file not found or not readable.';
						$ok      = false;
					} else {
						try {
							$svg     = Limits\read_file( $path, 'image/svg+xml' );
							$clean   = Images\strip_svg_metadata( $svg );
							$outcome = $clean !== $svg ? 'would change file' : 'already clean';
							$ok      = true;
						} catch ( \RuntimeException $e ) {
							$outcome = 'would flag: ' . $e->getMessage();
							$ok      = false;
						}
					}
				} else {
					Images\sanitize_svg_attachment( $id );
					$outcome = Status\get_detail( $id ) ?: Status\get_status( $id );
					$ok      = Status\is_sanitized( $id );
				}

				$results->add(
					[
						'attachment_id' => $id,
						'file'          => get_attached_file( $id ),
						'ok'            => $ok ? 'yes' : 'no',
						'reason'        => $outcome,
					],
					$ok
				);
				\WP_CLI::log( sprintf( '#%d: %s', $id, $outcome ) );
			},
			$results
		);
	}

	/**
	 * Process MP4/QuickTime attachments into a shared tally.
	 *
	 * @param array   $args       Positional args (specific IDs).
	 * @param array   $assoc_args Flags.
	 * @param bool    $dry_run    Whether to leave files and status untouched.
	 * @param Results $results    Tally the rows are added to.
	 */
	private function walk_videos( array $args, array $assoc_args, bool $dry_run, Results $results ) : void {
		walk_attachments(
			$args,
			$assoc_args,
			HM_MEDIA_PII_CLEANER_VIDEO_MIMES,
			static function ( \WP_Post $attachment ) use ( $dry_run, $results ) : void {
				$id = $attachment->ID;
				if ( $dry_run ) {
					$path = get_attached_file( $id );
					if ( ! $path || ! is_readable( $path ) ) {
						$outcome = 'would flag: Attachment file not found or not readable.';
						$ok      = false;
					} else {
						try {
							$mime    = in_array( $attachment->post_mime_type, HM_MEDIA_PII_CLEANER_VIDEO_MIMES, true ) ? $attachment->post_mime_type : 'video/mp4';
							$result  = Video\strip_video_metadata( Limits\read_file( $path, $mime ) );
							$outcome = 'would strip ' . $result['stripped_boxes'] . ' box(es)';
							$ok      = true;
						} catch ( \RuntimeException $e ) {
							$outcome = 'would flag (not quarantined): ' . $e->getMessage();
							$ok      = false;
						}
					}
				} else {
					Video\sanitize_video_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] );
					$outcome = Status\get_detail( $id ) ?: Status\get_status( $id );
					$ok      = Status\is_sanitized( $id );
				}

				$results->add(
					[
						'attachment_id' => $id,
						'file'          => get_attached_file( $id ),
						'ok'            => $ok ? 'yes' : 'no',
						'reason'        => $outcome,
					],
					$ok
				);
				\WP_CLI::log( sprintf( '#%d: %s', $id, $outcome ) );
			},
			$results
		);
	}

	/**
	 * Process DOCX/XLSX/PPTX attachments into a shared tally.
	 *
	 * @param array   $args       Positional args (specific IDs).
	 * @param array   $assoc_args Flags.
	 * @param bool    $dry_run    Whether to leave files and status untouched.
	 * @param Results $results    Tally the rows are added to.
	 */
	private function walk_ooxml( array $args, array $assoc_args, bool $dry_run, Results $results ) : void {
		walk_attachments(
			$args,
			$assoc_args,
			HM_MEDIA_PII_CLEANER_OOXML_MIMES,
			static function ( \WP_Post $attachment ) use ( $dry_run, $results ) : void {
				$id = $attachment->ID;
				if ( $dry_run ) {
					$path = get_attached_file( $id );
					if ( ! $path || ! is_readable( $path ) ) {
						$outcome = 'would flag: Attachment file not found or not readable.';
						$ok      = false;
					} else {
						try {
							$mime    = in_array( $attachment->post_mime_type, HM_MEDIA_PII_CLEANER_OOXML_MIMES, true ) ? $attachment->post_mime_type : HM_MEDIA_PII_CLEANER_OOXML_MIMES[0];
							$result  = Ooxml\strip_ooxml_metadata( Limits\read_file( $path, $mime ) );
							$outcome = 'would strip/filter ' . $result['stripped_parts'] . ' part(s)';
							$ok      = true;
						} catch ( \RuntimeException $e ) {
							$outcome = 'would flag: ' . $e->getMessage();
							$ok      = false;
						}
					}
				} else {
					Ooxml\sanitize_ooxml_attachment( $id, wp_get_attachment_metadata( $id ) ?: [] );
					$outcome = Status\get_detail( $id ) ?: Status\get_status( $id );
					$ok      = Status\is_sanitized( $id );
				}

				$results->add(
					[
						'attachment_id' => $id,
						'file'          => get_attached_file( $id ),
						'ok'            => $ok ? 'yes' : 'no',
						'reason'        => $outcome,
					],
					$ok
				);
				\WP_CLI::log( sprintf( '#%d: %s', $id, $outcome ) );
			},
			$results
		);
	}
}
