<?php
/**
 * Image sanitization orchestration. This plugin owns metadata policy while
 * EWWW Image Optimizer remains responsible for compression: EWWW's blanket
 * metadata removal is disabled so the allowlisted XMP fields can survive,
 * then this plugin sanitizes before and verifies after EWWW's optimizer.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Images;

use HM\MediaPiiCleaner\Fields;
use HM\MediaPiiCleaner\Status;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Limits;

/**
 * Bootstrap image-sanitization hooks.
 */
function bootstrap() {
	enforce_ewww_metadata_policy();
	add_filter( 'wp_update_attachment_metadata', __NAMESPACE__ . '\\filter_attachment_metadata', 999 );
}

/**
 * Remove EXIF/IPTC copied into WordPress metadata before the file was cleaned.
 * WordPress exposes image_meta through the public REST media response.
 * Orientation is retained as an image-editor instruction, not authoring data.
 *
 * @param array|false $metadata Attachment metadata, or false when deleting it.
 * @return array|false
 */
function filter_attachment_metadata( $metadata ) {
	if ( is_array( $metadata ) && isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ) {
		$metadata['image_meta'] = array_intersect_key(
			$metadata['image_meta'],
			array_flip( [ ...Fields\image_meta_keys(), 'orientation' ] )
		);
	}
	return $metadata;
}

/**
 * Apply the same policy to stored metadata during a CLI backfill.
 *
 * @param int $attachment_id Attachment post ID.
 */
function sanitize_stored_image_metadata( int $attachment_id ) : void {
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$clean    = filter_attachment_metadata( $metadata );
	if ( $clean !== $metadata ) {
		wp_update_attachment_metadata( $attachment_id, $clean );
	}
}

/**
 * Disable EWWW's all-or-nothing metadata removal. This plugin performs the
 * stricter field-level allowlist pass on both sides of EWWW's upload-time
 * optimizer, so allowing EWWW to strip first would irreversibly discard the
 * title/organisation/copyright/date/version/language values the allowlist retains.
 */
function enforce_ewww_metadata_policy() : void {
	if ( ! function_exists( 'ewww_image_optimizer_get_option' ) ) {
		return;
	}

	$changed = false;
	if ( get_option( 'ewww_image_optimizer_metadata_remove' ) ) {
		update_option( 'ewww_image_optimizer_metadata_remove', false );
		$changed = true;
	}
	if ( is_multisite() && get_site_option( 'ewww_image_optimizer_metadata_remove' ) ) {
		update_site_option( 'ewww_image_optimizer_metadata_remove', false );
		$changed = true;
	}

	// Returning integer zero (rather than false, which means "do not
	// short-circuit") guarantees EWWW sees this option as disabled in both
	// single-site and network-activated configurations.
	add_filter( 'pre_option_ewww_image_optimizer_metadata_remove', __NAMESPACE__ . '\\return_zero' );
	add_filter( 'pre_site_option_ewww_image_optimizer_metadata_remove', __NAMESPACE__ . '\\return_zero' );

	if ( $changed ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_ewww_policy_notice' );
	}
}

/**
 * Provide the false-y value required by WordPress' pre-option filters.
 *
 * @return int
 */
function return_zero() : int {
	return 0;
}

/**
 * Notice shown when this plugin takes ownership of EWWW's metadata setting.
 */
function render_ewww_policy_notice() : void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-info"><p>' . esc_html__(
		'EWWW Image Optimizer\'s blanket "Remove Metadata" setting has been disabled because HM Media PII Cleaner now applies and verifies the stricter metadata allowlist around EWWW optimization.',
		'hm-media-pii-cleaner'
	) . '</p></div>';
}

/**
 * Sanitize a JPEG attachment: strip EXIF/XMP (APP1), IPTC (APP13), and
 * comment (COM) marker segments ourselves, then verify. This does not rely
 * on trusting EWWW Image Optimizer's own JPEG stripping, whose completeness
 * (IPTC/XMP as well as EXIF) depends on its configuration and available
 * binaries. Running our own verified stripper removes that assumption.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 */
function sanitize_jpeg_attachment( int $attachment_id, array $metadata ) : void {
	strip_chunked_image_attachment(
		$attachment_id,
		$metadata,
		__NAMESPACE__ . '\\strip_jpeg_metadata',
		'stripped_segments',
		'JPEG'
	);

	if ( HM_MEDIA_PII_CLEANER_STATUS_SANITIZED !== Status\get_status( $attachment_id ) ) {
		return; // Already flagged by strip_chunked_image_attachment() — nothing more to check.
	}

	$leak = find_residual_jpeg_metadata( $attachment_id );

	if ( null !== $leak ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $leak );
	}
}

/**
 * Spot-check a JPEG attachment's files for residual EXIF, IPTC, or XMP after
 * stripping — belt-and-suspenders on top of strip_jpeg_metadata()'s own
 * marker-type matching, in case some non-standard structure slipped through.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string|null A description of what leaked, or null if clean.
 */
function find_residual_jpeg_metadata( int $attachment_id ) : ?string {
	foreach ( get_all_attachment_file_paths( $attachment_id ) as $path ) {
		if ( ! is_readable( $path ) ) {
			continue;
		}

		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- exif_read_data() warns on non-EXIF JPEGs, which is the expected/desired case here.
		if ( false !== $exif ) {
			$leaky_tags = array_intersect_key( $exif, array_flip( [ 'Make', 'Model', 'Software', 'Artist', 'Copyright', 'GPSLatitude', 'GPSLongitude' ] ) );
			if ( ! empty( $leaky_tags ) ) {
				return "Residual EXIF on $path: " . implode( ', ', array_keys( $leaky_tags ) );
			}
		}

		$info = [];
		@getimagesize( $path, $info ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! empty( $info['APP13'] ) && iptcparse( $info['APP13'] ) ) {
			return "Residual IPTC data on $path.";
		}

		try {
			$bytes     = Limits\read_file( $path, 'image/jpeg' );
			$rechecked = strip_jpeg_metadata( $bytes );
		} catch ( \RuntimeException $e ) {
			return "JPEG verification failed on $path: " . $e->getMessage();
		}
		if ( $rechecked['data'] !== $bytes ) {
			return "Residual or structurally invalid metadata on $path.";
		}
	}

	return null;
}

/**
 * Sanitize a PNG attachment: always run this plugin's own byte-level
 * stripper — never defer to EWWW, whether via its local binary path or its
 * Cloud API. Same reasoning as sanitize_webp_attachment(): EWWW's stripping
 * is a blanket strip, not the field-level allowlist (title/copyright/date/
 * language) this plugin requires, so deferring to it — for example if a
 * Cloud API key is ever configured, which needs no local binary at all and
 * so isn't ruled out by a host disabling `shell_exec`/`exec` — would
 * silently regress that requirement. This plugin's own
 * stripper is lossless by construction and lets us always take the field-
 * preserving path regardless of what else already processed the file.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 */
function sanitize_png_attachment( int $attachment_id, array $metadata ) : void {
	strip_chunked_image_attachment(
		$attachment_id,
		$metadata,
		__NAMESPACE__ . '\\strip_png_metadata',
		'stripped_chunks',
		'PNG'
	);
}

/**
 * Sanitize a WebP attachment: always run this plugin's own byte-level
 * stripper — never defer to EWWW/Imagick.
 *
 * Deferring to EWWW whenever the Imagick PHP extension is loaded is not
 * safe: many managed hosts ship Imagick, so that branch would trigger on
 * every WebP upload — but EWWW's stripping is a blanket strip, not the
 * field-level allowlist (title/copyright/date/language) this plugin
 * requires. Deferring to it would silently regress that requirement. This
 * plugin's own stripper implements the allowlist and is lossless by
 * construction (chunk-structured rewrite, no re-encode), so there's no
 * reason left to prefer a third-party tool that can't do what's required —
 * always run it, regardless of what else already processed the file.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 */
function sanitize_webp_attachment( int $attachment_id, array $metadata ) : void {
	strip_chunked_image_attachment(
		$attachment_id,
		$metadata,
		__NAMESPACE__ . '\\strip_webp_metadata',
		'stripped_chunks',
		'WebP'
	);
}

/**
 * Shared driver for the chunk-based PNG/WebP strippers: runs the stripper
 * across the original file and every generated sub-size, verifies each
 * result re-parses as a structurally valid image with unchanged pixel
 * dimensions before writing it, and flags on any failure.
 *
 * @param int      $attachment_id Attachment post ID.
 * @param array    $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 * @param callable $strip_fn      e.g. 'HM\MediaPiiCleaner\Images\strip_png_metadata'.
 * @param string   $count_key     Result array key holding the stripped-chunk count.
 * @param string   $format_label  Human-readable format name for status/flag messages.
 */
function strip_chunked_image_attachment( int $attachment_id, array $metadata, callable $strip_fn, string $count_key, string $format_label ) : void {
	$paths          = get_all_attachment_file_paths( $attachment_id, $metadata );
	$total_stripped = 0;
	$unreadable     = [];
	$mime           = match ( $format_label ) {
		'JPEG'  => 'image/jpeg',
		'PNG'   => 'image/png',
		'WebP'  => 'image/webp',
		default => '',
	};

	foreach ( $paths as $path ) {
		if ( ! is_readable( $path ) ) {
			$unreadable[] = $path;
			continue;
		}

		try {
			$original  = Limits\read_file( $path, $mime );
			$result    = $strip_fn( $original );
			$rechecked = $strip_fn( $result['data'] );
		} catch ( \RuntimeException $e ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "$format_label parse error on $path: " . $e->getMessage() );
			return;
		}

		if ( $rechecked['data'] !== $result['data'] ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "$format_label metadata sanitizer was not idempotent on $path." );
			return;
		}

		$before_size = @getimagesizefromstring( $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$after_size  = @getimagesizefromstring( $result['data'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $after_size || ( $before_size[0] ?? null ) !== $after_size[0] || ( $before_size[1] ?? null ) !== $after_size[1] ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "$format_label dimensions changed (or became unreadable) after stripping on $path — rejected to avoid a visual regression." );
			return;
		}

		if ( $result[ $count_key ] > 0 ) {
			if ( ! Files\write_atomically( $path, $result['data'] ) ) {
				Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "$format_label metadata could not be safely written to $path." );
				return;
			}
			$total_stripped += $result[ $count_key ];
		}
	}

	if ( [] !== $unreadable ) {
		$list = implode( ', ', $unreadable );
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "$format_label sanitization incomplete — could not read: $list" );
		return;
	}

	Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_SANITIZED, "Stripped $total_stripped $format_label metadata chunk(s)." );
}

/**
 * Sanitize the JPEG preview and sub-sizes WordPress generates for a PDF.
 *
 * PDF attachment metadata lists these public JPEGs under `sizes`; the PDF
 * itself remains the attached file. Treating only the PDF would therefore
 * leave a second public metadata surface unsanitized.
 *
 * @param int        $attachment_id Attachment post ID.
 * @param array|null $metadata      PDF attachment metadata; fetched if omitted.
 * @param bool       $dry_run       Whether to verify without writing changes.
 * @return array{ok: bool, detail: string}
 */
function sanitize_pdf_preview_images( int $attachment_id, ?array $metadata = null, bool $dry_run = false ) : array {
	$metadata ??= wp_get_attachment_metadata( $attachment_id ) ?: [];
	$paths       = get_pdf_preview_file_paths( $attachment_id, $metadata );
	$stripped    = 0;

	foreach ( $paths as $path ) {
		if ( ! is_readable( $path ) ) {
			return [
				'ok'     => false,
				'detail' => "PDF preview JPEG is not readable: $path.",
			];
		}

		try {
			$original  = Limits\read_file( $path, 'image/jpeg' );
			$result    = strip_jpeg_metadata( $original );
			$rechecked = strip_jpeg_metadata( $result['data'] );
		} catch ( \RuntimeException $e ) {
			return [
				'ok'     => false,
				'detail' => "PDF preview JPEG parse error on $path: " . $e->getMessage(),
			];
		}

		if ( $rechecked['data'] !== $result['data'] ) {
			return [
				'ok'     => false,
				'detail' => "PDF preview JPEG sanitizer was not idempotent on $path.",
			];
		}

		$before_size = @getimagesizefromstring( $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$after_size  = @getimagesizefromstring( $result['data'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $before_size || false === $after_size || $before_size[0] !== $after_size[0] || $before_size[1] !== $after_size[1] ) {
			return [
				'ok'     => false,
				'detail' => "PDF preview JPEG dimensions changed (or became unreadable) on $path.",
			];
		}

		if ( $result['stripped_segments'] > 0 ) {
			if ( ! $dry_run && ! Files\write_atomically( $path, $result['data'] ) ) {
				return [
					'ok'     => false,
					'detail' => "PDF preview JPEG metadata could not be safely written to $path.",
				];
			}
			$stripped += $result['stripped_segments'];
		}
	}

	return [
		'ok'     => true,
		'detail' => sprintf( 'Stripped %d metadata segment(s) from %d PDF preview JPEG(s).', $stripped, count( $paths ) ),
	];
}

/**
 * Resolve only JPEG derivatives generated for a PDF attachment.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      PDF attachment metadata.
 * @return string[]
 */
function get_pdf_preview_file_paths( int $attachment_id, array $metadata ) : array {
	$pdf_path = get_attached_file( $attachment_id );
	if ( ! $pdf_path || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
		return [];
	}

	$directory = dirname( $pdf_path );
	$paths     = [];
	foreach ( $metadata['sizes'] as $size ) {
		$file = $size['file'] ?? '';
		if ( ! is_string( $file ) || '' === $file ) {
			continue;
		}
		$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		$mime      = $size['mime-type'] ?? '';
		if ( 'image/jpeg' !== $mime && ! in_array( $extension, [ 'jpg', 'jpeg' ], true ) ) {
			continue;
		}
		$paths[] = $directory . '/' . wp_basename( $file );
	}

	return array_values( array_unique( $paths ) );
}

/**
 * Remove generated PDF previews when any one of them cannot be sanitized.
 *
 * During the metadata-generation filter these files may not yet be recorded
 * in the database, so the normal attachment quarantine cannot reliably find
 * them. Removing the passed-in paths prevents a failed preview from remaining
 * directly addressable under uploads.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      PDF attachment metadata from the active filter.
 * @return bool Whether every preview is absent afterward.
 */
function remove_pdf_preview_images( int $attachment_id, array $metadata ) : bool {
	$removed = true;
	foreach ( get_pdf_preview_file_paths( $attachment_id, $metadata ) as $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		if ( is_file( $path ) ) {
			$removed = false;
		}
	}

	return $removed;
}

/**
 * Verify that a JPEG, PNG, or WebP attachment can be stripped without
 * changing its dimensions, without replacing files or changing its status.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata Attachment metadata.
 * @return array{ok: bool, detail: string}
 */
function inspect_chunked_image_attachment( int $attachment_id, array $metadata ) : array {
	$mime = get_post_mime_type( $attachment_id );
	$format = match ( $mime ) {
		'image/jpeg' => [ __NAMESPACE__ . '\\strip_jpeg_metadata', 'stripped_segments', 'JPEG' ],
		'image/png'  => [ __NAMESPACE__ . '\\strip_png_metadata', 'stripped_chunks', 'PNG' ],
		'image/webp' => [ __NAMESPACE__ . '\\strip_webp_metadata', 'stripped_chunks', 'WebP' ],
		default      => null,
	};

	if ( null === $format ) {
		return [
			'ok'     => false,
			'detail' => "Unsupported image MIME type: $mime.",
		];
	}

	[ $strip_fn, $count_key, $format_label ] = $format;
	$total_stripped                          = 0;

	foreach ( get_all_attachment_file_paths( $attachment_id, $metadata ) as $path ) {
		if ( ! is_readable( $path ) ) {
			return [
				'ok'     => false,
				'detail' => "$format_label file is not readable: $path.",
			];
		}

		try {
			$original = Limits\read_file( $path, $mime );
			$result = $strip_fn( $original );
		} catch ( \RuntimeException $e ) {
			return [
				'ok'     => false,
				'detail' => "$format_label parse error on $path: " . $e->getMessage(),
			];
		}

		$before_size = @getimagesizefromstring( $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$after_size  = @getimagesizefromstring( $result['data'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $after_size || ( $before_size[0] ?? null ) !== $after_size[0] || ( $before_size[1] ?? null ) !== $after_size[1] ) {
			return [
				'ok'     => false,
				'detail' => "$format_label dimensions would change (or become unreadable) on $path.",
			];
		}

		$total_stripped += $result[ $count_key ];
	}

	return [
		'ok'     => true,
		'detail' => "Would strip $total_stripped $format_label metadata chunk(s).",
	];
}

/**
 * Sanitize a GIF attachment's original file and every generated sub-size.
 *
 * @param int   $attachment_id Attachment post ID.
 * @param array $metadata      Attachment metadata, per wp_generate_attachment_metadata.
 */
function sanitize_gif_attachment( int $attachment_id, array $metadata ) : void {
	$paths      = get_all_attachment_file_paths( $attachment_id, $metadata );
	$unreadable = [];

	$total_stripped = 0;

	foreach ( $paths as $path ) {
		if ( ! is_readable( $path ) ) {
			$unreadable[] = $path;
			continue;
		}

		try {
			$original    = Limits\read_file( $path, 'image/gif' );
			$before      = strip_gif_metadata( $original );
			$reparsed    = strip_gif_metadata( $before['data'] ); // idempotency + frame-count verification.
		} catch ( \RuntimeException $e ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "GIF parse error on $path: " . $e->getMessage() );
			return;
		}

		if ( $before['frame_count'] !== $reparsed['frame_count'] ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "GIF frame-count mismatch after stripping on $path." );
			return;
		}

		if ( $before['stripped_blocks'] > 0 ) {
			if ( ! Files\write_atomically( $path, $before['data'] ) ) {
				Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, "GIF metadata could not be safely written to $path." );
				return;
			}
			$total_stripped += $before['stripped_blocks'];
		}
	}

	if ( [] !== $unreadable ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'GIF sanitization incomplete — could not read: ' . implode( ', ', $unreadable ) );
		return;
	}

	Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_SANITIZED, "Stripped $total_stripped GIF metadata block(s)." );
}

/**
 * Sanitize an SVG attachment's file.
 *
 * @param int $attachment_id Attachment post ID.
 */
function sanitize_svg_attachment( int $attachment_id ) : void {
	$path = get_attached_file( $attachment_id );

	if ( ! $path || ! is_readable( $path ) ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'SVG sanitization incomplete — attachment file is missing or unreadable.' );
		return;
	}

	try {
		$original    = Limits\read_file( $path, 'image/svg+xml' );
		$before_dims = get_svg_dimensions( $original );
		$clean       = strip_svg_metadata( $original );
		$after_dims  = get_svg_dimensions( $clean );
	} catch ( \RuntimeException $e ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'SVG parse error: ' . $e->getMessage() );
		return;
	}

	if ( $before_dims !== $after_dims ) {
		Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'SVG viewBox/width/height changed after stripping — rejected to avoid a visual regression.' );
		return;
	}

	if ( $clean !== $original ) {
		if ( ! Files\write_atomically( $path, $clean ) ) {
			Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, 'SVG metadata could not be safely written.' );
			return;
		}
	}

	Status\set_status( $attachment_id, HM_MEDIA_PII_CLEANER_STATUS_SANITIZED );
}

/**
 * Get the absolute file paths for an attachment's original file and every
 * generated sub-size (thumbnail/medium/large/scaled/etc.).
 *
 * @param int        $attachment_id Attachment post ID.
 * @param array|null $metadata      Attachment metadata; fetched if not supplied.
 * @return string[]
 */
function get_all_attachment_file_paths( int $attachment_id, ?array $metadata = null ) : array {
	return Files\attachment_file_paths( $attachment_id, $metadata );
}
