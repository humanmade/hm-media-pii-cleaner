<?php
/**
 * Resource budgets for processing untrusted media.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Limits;

/**
 * Is a MIME type processed by this plugin?
 *
 * @param string $mime MIME type.
 */
function is_covered_mime( string $mime ) : bool {
	return in_array(
		$mime,
		[
			...HM_MEDIA_PII_CLEANER_IMAGE_MIMES,
			HM_MEDIA_PII_CLEANER_PDF_MIME,
			...HM_MEDIA_PII_CLEANER_VIDEO_MIMES,
			...HM_MEDIA_PII_CLEANER_OOXML_MIMES,
		],
		true
	);
}

/**
 * Return the configured hard ceiling for a covered MIME type.
 *
 * @param string $mime MIME type.
 */
function configured_limit_for_mime( string $mime ) : int {
	if ( in_array( $mime, HM_MEDIA_PII_CLEANER_IMAGE_MIMES, true ) ) {
		return max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_IMAGE_BYTES );
	}
	if ( HM_MEDIA_PII_CLEANER_PDF_MIME === $mime ) {
		return max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_PDF_BYTES );
	}
	if ( in_array( $mime, HM_MEDIA_PII_CLEANER_VIDEO_MIMES, true ) ) {
		return max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_VIDEO_BYTES );
	}
	if ( in_array( $mime, HM_MEDIA_PII_CLEANER_OOXML_MIMES, true ) ) {
		return max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_BYTES );
	}

	return 0;
}

/**
 * Estimate how many simultaneous full-file buffers a format can require.
 *
 * @param string $mime MIME type.
 */
function memory_multiplier_for_mime( string $mime ) : int {
	if ( HM_MEDIA_PII_CLEANER_PDF_MIME === $mime ) {
		return 6;
	}
	if ( in_array( $mime, HM_MEDIA_PII_CLEANER_VIDEO_MIMES, true ) ) {
		return 5;
	}

	return 4;
}

/**
 * Convert a PHP ini byte value (for example 128M or -1) to bytes.
 *
 * @param string $value Raw ini value.
 */
function parse_ini_bytes( string $value ) : int {
	$value = trim( $value );
	if ( '' === $value ) {
		return 0;
	}
	if ( '-1' === $value ) {
		return -1;
	}

	$unit   = strtolower( substr( $value, -1 ) );
	$number = (float) $value;
	$factor = match ( $unit ) {
		'g'     => 1024 ** 3,
		'm'     => 1024 ** 2,
		'k'     => 1024,
		default => 1,
	};

	return max( 0, (int) floor( $number * $factor ) );
}

/**
 * Return the lower of the configured ceiling and the current memory-safe size.
 * Optional memory arguments make the calculation deterministic in tests.
 *
 * @param string   $mime               MIME type.
 * @param int|null $memory_limit_bytes PHP memory limit in bytes; null reads ini.
 * @param int|null $memory_usage_bytes Current allocated memory; null reads PHP.
 */
function processing_limit_for_mime( string $mime, ?int $memory_limit_bytes = null, ?int $memory_usage_bytes = null ) : int {
	$configured = configured_limit_for_mime( $mime );
	if ( 0 === $configured ) {
		return 0;
	}

	$memory_limit_bytes ??= parse_ini_bytes( (string) ini_get( 'memory_limit' ) );
	if ( $memory_limit_bytes < 0 ) {
		return $configured;
	}

	$memory_usage_bytes ??= memory_get_usage( true );
	$available            = $memory_limit_bytes - $memory_usage_bytes - max( 0, (int) HM_MEDIA_PII_CLEANER_MEMORY_RESERVE_BYTES );
	if ( $available <= 0 ) {
		return 0;
	}

	$memory_safe = intdiv( $available, memory_multiplier_for_mime( $mime ) );

	return min( $configured, max( 0, $memory_safe ) );
}

/**
 * Explain why a byte length exceeds its processing budget.
 *
 * @param int      $size               File/data size in bytes.
 * @param string   $mime               MIME type.
 * @param int|null $memory_limit_bytes Optional deterministic memory limit.
 * @param int|null $memory_usage_bytes Optional deterministic memory usage.
 * @return string Empty when allowed; an error otherwise.
 */
function size_error( int $size, string $mime, ?int $memory_limit_bytes = null, ?int $memory_usage_bytes = null ) : string {
	$limit = processing_limit_for_mime( $mime, $memory_limit_bytes, $memory_usage_bytes );
	if ( $size >= 0 && $size <= $limit ) {
		return '';
	}

	return sprintf(
		'File is %s; the safe processing limit for %s in this PHP worker is %s.',
		format_bytes( max( 0, $size ) ),
		$mime,
		format_bytes( $limit )
	);
}

/**
 * Assert that a file can be processed within the current resource budget.
 *
 * @param string $path File path.
 * @param string $mime MIME type.
 * @throws \RuntimeException When the file size is unavailable or over budget.
 */
function assert_file_processable( string $path, string $mime ) : void {
	clearstatcache( true, $path );
	$size = filesize( $path );
	if ( false === $size ) {
		throw new \RuntimeException( 'Unable to determine the attachment file size.' );
	}

	$error = size_error( $size, $mime );
	if ( '' !== $error ) {
		throw new \RuntimeException( $error );
	}
}

/**
 * Assert that an in-memory payload fits the current resource budget.
 *
 * @param string $data Bytes to process.
 * @param string $mime MIME type.
 * @throws \RuntimeException When the payload is over budget.
 */
function assert_data_processable( string $data, string $mime ) : void {
	$error = size_error( strlen( $data ), $mime );
	if ( '' !== $error ) {
		throw new \RuntimeException( $error );
	}
}

/**
 * Read a file without ever consuming more than its current processing budget.
 *
 * The stat check gives a useful early error, while the capped stream read also
 * protects against a file that changes between the stat and the read.
 *
 * @param string $path File path.
 * @param string $mime MIME type.
 * @return string File bytes.
 * @throws \RuntimeException When the file cannot be safely read.
 */
function read_file( string $path, string $mime ) : string {
	assert_file_processable( $path, $mime );
	$limit  = processing_limit_for_mime( $mime );
	$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

	if ( false === $handle ) {
		throw new \RuntimeException( 'Unable to open the attachment file for reading.' );
	}

	try {
		$data = stream_get_contents( $handle, $limit + 1 );
		if ( false === $data ) {
			throw new \RuntimeException( 'Unable to read the attachment file.' );
		}

		if ( strlen( $data ) > $limit ) {
			throw new \RuntimeException( size_error( strlen( $data ), $mime ) );
		}

		// feof() is not set until a read passes the final byte, so probe once.
		if ( '' !== fread( $handle, 1 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			throw new \RuntimeException( 'Attachment grew beyond its safe processing limit while it was being read.' );
		}
	} finally {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	return $data;
}

/**
 * Human-readable binary byte count without requiring WordPress formatting helpers.
 *
 * @param int $bytes Byte count.
 */
function format_bytes( int $bytes ) : string {
	if ( $bytes >= 1024 ** 3 ) {
		return round( $bytes / ( 1024 ** 3 ), 1 ) . ' GiB';
	}
	if ( $bytes >= 1024 ** 2 ) {
		return round( $bytes / ( 1024 ** 2 ), 1 ) . ' MiB';
	}
	if ( $bytes >= 1024 ) {
		return round( $bytes / 1024, 1 ) . ' KiB';
	}

	return $bytes . ' bytes';
}
