<?php
/**
 * Filesystem helpers for safe replacement and quarantine of attachment files.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Files;

/**
 * Replace a file only when the full replacement has been written and can be
 * read back unchanged. Keeping the temporary file beside the destination
 * makes the final rename atomic on the site's normal filesystem.
 *
 * @param string $path Destination file path.
 * @param string $data Complete replacement file contents.
 * @return bool Whether the replacement was committed and verified.
 */
function write_atomically( string $path, string $data ) : bool {
	$directory = dirname( $path );

	if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem cannot express the atomic write-then-rename this function is built on.
		return false;
	}

	$tmp_path = $directory . '/.hm-media-pii-cleaner-' . wp_generate_uuid4() . '.tmp';
	$length        = strlen( $data );
	$expected_hash = hash( 'sha256', $data );
	$written       = file_put_contents( $tmp_path, $data, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	if ( $length !== $written ) {
		if ( is_file( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		return false;
	}

	if ( ! rename( $tmp_path, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		wp_delete_file( $tmp_path );
		return false;
	}

	clearstatcache( true, $path );
	$written_size = filesize( $path );
	$written_hash = hash_file( 'sha256', $path );

	return $length === $written_size && is_string( $written_hash ) && hash_equals( $expected_hash, $written_hash );
}

/**
 * Return every publicly-addressable file belonging to an attachment.
 *
 * WordPress keeps a large upload's unscaled source in `original_image` while
 * `_wp_attached_file` points to the scaled derivative. That source is exposed
 * through the REST media response, so it must be handled alongside sub-sizes.
 *
 * @param int         $attachment_id Attachment post ID.
 * @param array|null  $metadata Attachment metadata, fetched when omitted.
 * @return string[]
 */
function attachment_file_paths( int $attachment_id, ?array $metadata = null ) : array {
	$attached_file = get_attached_file( $attachment_id );

	if ( ! $attached_file ) {
		return [];
	}

	$metadata ??= wp_get_attachment_metadata( $attachment_id );
	$paths       = [ $attached_file ];
	$directory   = dirname( $attached_file );

	if ( ! empty( $metadata['original_image'] ) ) {
		$paths[] = $directory . '/' . wp_basename( $metadata['original_image'] );
	}

	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $directory . '/' . wp_basename( $size['file'] );
			}
		}
	}

	// Image edits retain earlier files under their original public URLs.
	$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
	if ( is_array( $backups ) ) {
		foreach ( $backups as $backup ) {
			if ( ! empty( $backup['file'] ) && is_string( $backup['file'] ) ) {
				$paths[] = $directory . '/' . wp_basename( $backup['file'] );
			}
		}
	}

	return array_unique( $paths );
}

/**
 * Move public files for an unsafe attachment outside the document root.
 *
 * The destination can be overridden by hosts with a dedicated private volume.
 * If preserving a copy fails, deletion is still attempted: leaving a known
 * unsafe file at its uploads URL is worse than requiring restoration from a
 * backup.
 *
 * @param int $attachment_id Attachment post ID.
 * @return array{ok: bool, detail: string}
 */
function quarantine_attachment( int $attachment_id ) : array {
	$existing_quarantine = (string) get_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_QUARANTINE_META, true );
	if ( '' !== $existing_quarantine ) {
		return [
			'ok'     => true,
			'detail' => 'Attachment files are already quarantined.',
		];
	}

	$paths = array_filter( attachment_file_paths( $attachment_id ), 'is_file' );
	if ( empty( $paths ) ) {
		return [
			'ok'     => true,
			'detail' => 'No public attachment files remained to quarantine.',
		];
	}

	$base_directory = defined( 'HM_MEDIA_PII_CLEANER_QUARANTINE_DIR' )
		? HM_MEDIA_PII_CLEANER_QUARANTINE_DIR
		: dirname( ABSPATH ) . '/hm-media-pii-cleaner-quarantine';
	$directory      = trailingslashit( $base_directory ) . $attachment_id;

	if ( ! wp_mkdir_p( $directory ) ) {
		return delete_unquarantinable_files( $paths );
	}

	@chmod( $base_directory, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- quarantine must not be world-readable; a failure here is non-fatal.
	@chmod( $directory, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- quarantine must not be world-readable; a failure here is non-fatal.

	$quarantined_paths = [];
	foreach ( $paths as $path ) {
		$destination = $directory . '/' . wp_unique_filename( $directory, wp_basename( $path ) );

		if ( ! move_file_to_quarantine( $path, $destination ) ) {
			return delete_unquarantinable_files( array_merge( [ $path ], array_diff( $paths, $quarantined_paths ) ) );
		}

		$quarantined_paths[] = $path;
	}

	update_post_meta( $attachment_id, HM_MEDIA_PII_CLEANER_QUARANTINE_META, $directory );

	return [
		'ok'     => true,
		'detail' => 'Public attachment files were quarantined.',
	];
}

/**
 * Move a public file to the private quarantine, falling back to a verified
 * copy-and-delete when the paths are on different filesystems.
 *
 * @param string $source Source path in uploads.
 * @param string $destination Private destination path.
 * @return bool
 */
function move_file_to_quarantine( string $source, string $destination ) : bool {
	if ( rename( $source, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		return true;
	}

	if ( ! copy_file_atomically( $source, $destination ) ) {
		return false;
	}

	wp_delete_file( $source );
	clearstatcache( true, $source );

	return ! is_file( $source );
}

/**
 * Copy a file through a sibling temporary path without loading it into memory.
 *
 * @param string $source Source file path.
 * @param string $destination Destination file path.
 * @return bool Whether the destination was committed and verified.
 */
function copy_file_atomically( string $source, string $destination ) : bool {
	$directory = dirname( $destination );
	if ( ! is_file( $source ) || ! is_readable( $source ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem cannot express the streamed copy-then-rename this function is built on.
		return false;
	}

	$tmp_path = $directory . '/.hm-media-pii-cleaner-' . wp_generate_uuid4() . '.tmp';
	if ( ! copy( $source, $tmp_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( is_file( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		return false;
	}

	clearstatcache( true, $source );
	clearstatcache( true, $tmp_path );
	$source_size = filesize( $source );
	$tmp_size    = filesize( $tmp_path );
	$source_hash = hash_file( 'sha256', $source );
	$tmp_hash    = hash_file( 'sha256', $tmp_path );

	if (
		false === $source_size
		|| $source_size !== $tmp_size
		|| ! is_string( $source_hash )
		|| ! is_string( $tmp_hash )
		|| ! hash_equals( $source_hash, $tmp_hash )
		|| ! rename( $tmp_path, $destination ) // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	) {
		if ( is_file( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		return false;
	}

	clearstatcache( true, $destination );
	$destination_size = filesize( $destination );
	$destination_hash = hash_file( 'sha256', $destination );

	return $source_size === $destination_size
		&& is_string( $destination_hash )
		&& hash_equals( $source_hash, $destination_hash );
}

/**
 * Last-resort removal when no private copy can be created. This deliberately
 * favours preventing a known metadata leak over retaining a public copy.
 *
 * @param string[] $paths Public file paths.
 * @return array{ok: bool, detail: string}
 */
function delete_unquarantinable_files( array $paths ) : array {
	$failed = [];
	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
			clearstatcache( true, $path );
			if ( is_file( $path ) ) {
				$failed[] = $path;
			}
		}
	}

	if ( ! empty( $failed ) ) {
		return [
			'ok'     => false,
			'detail' => 'Could not remove unsafe public file(s): ' . implode( ', ', $failed ),
		];
	}

	return [
		'ok'     => true,
		'detail' => 'Unsafe public attachment files were removed because quarantine storage was unavailable.',
	];
}
