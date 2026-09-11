<?php
/**
 * Byte-level JPEG metadata stripper.
 *
 * Metadata normally appears before the first Start-Of-Scan (SOS), but JPEG
 * permits additional marker segments between progressive scans and before
 * EOI. The walker therefore parses the complete marker stream and skips
 * entropy-coded scan data with JPEG's byte-stuffing and restart-marker rules.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Images;

use HM\MediaPiiCleaner\Fields;
use HM\MediaPiiCleaner\XmpFilter;

/**
 * APP markers are treated as metadata by default. The only exceptions are
 * narrowly identified rendering/structure payloads: JFIF/JFXX in APP0, ICC
 * color profiles in APP2, and Adobe color-transform data in APP14. APP1 XMP
 * is rebuilt through the allowlist; every other APP payload and COM comment
 * is dropped.
 *
 * EXIF (binary/TIFF-structured) and IPTC (binary) segments are dropped
 * wholesale, but their allowlisted title/copyright/date/language values are
 * read first and moved into a fresh XMP packet. This avoids writing TIFF or
 * IPTC while preserving the permitted semantics. APP1's XMP flavour is
 * filtered directly through the same allowlist.
 */
const JPEG_APP_MARKER_MIN = 0xE0;
const JPEG_APP_MARKER_MAX = 0xEF;
const JPEG_COM_MARKER     = 0xFE;

/**
 * APP1 payload prefix identifying an XMP (rather than EXIF) segment, per
 * the XMP spec's JPEG embedding convention.
 */
const JPEG_XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\x00";
const JPEG_ICC_SIGNATURE = "ICC_PROFILE\x00";

/**
 * Strip EXIF/XMP/IPTC/comment marker segments from a JPEG's raw bytes.
 *
 * @param string $data Raw JPEG file contents.
 * @return array{data: string, stripped_segments: int}
 * @throws \RuntimeException If the header markers can't be safely walked.
 */
function strip_jpeg_metadata( string $data ) : array {
	$len = strlen( $data );

	if ( $len < 4 || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
		throw new \RuntimeException( 'Not a JPEG file (bad SOI marker).' );
	}

	$out                 = "\xFF\xD8";
	$pos                 = 2;
	$stripped            = 0;
	$xmp_packets         = [];
	$xmp_insert_position = null;

	while ( $pos < $len ) {
		if ( "\xFF" !== $data[ $pos ] ) {
			throw new \RuntimeException( "Expected a marker at offset $pos." );
		}

		$marker_start = $pos;
		while ( $pos < $len && "\xFF" === $data[ $pos ] ) {
			++$pos;
		}
		if ( $pos >= $len ) {
			throw new \RuntimeException( 'Truncated marker (fill bytes ran to end of file).' );
		}

		$marker_id = ord( $data[ $pos ] );
		++$pos;

		// Insert rebuilt XMP before EOI when a scan-less JPEG is encountered.
		if ( 0xD9 === $marker_id ) {
			if ( null === $xmp_insert_position ) {
				$xmp_insert_position = strlen( $out );
			}
			$out .= substr( $data, $marker_start, $pos - $marker_start );
			break;
		}

		if ( 0xDA === $marker_id ) {
			// Copy the SOS header and entropy-coded bytes through the next real
			// marker. Stuffed FF 00 bytes and restart markers belong to the scan.
			if ( $pos + 2 > $len ) {
				throw new \RuntimeException( 'Truncated SOS segment length.' );
			}
			$seg_len = unpack( 'n', substr( $data, $pos, 2 ) )[1];
			if ( $seg_len < 2 || $pos + $seg_len > $len ) {
				throw new \RuntimeException( 'Truncated SOS segment.' );
			}
			if ( null === $xmp_insert_position ) {
				$xmp_insert_position = strlen( $out );
			}
			$out .= substr( $data, $marker_start, ( $pos - $marker_start ) + $seg_len );
			$pos  += $seg_len;
			$scan_end = find_jpeg_scan_end( $data, $pos, $len );
			$out      .= substr( $data, $pos, $scan_end - $pos );
			$pos       = $scan_end;
			continue;
		}

		// Standalone markers with no length/payload (TEM, RSTn) — shouldn't
		// appear in the header before SOS in a well-formed file, but handled
		// defensively rather than treated as an error.
		if ( 0x01 === $marker_id || ( $marker_id >= 0xD0 && $marker_id <= 0xD7 ) ) {
			$out .= substr( $data, $marker_start, $pos - $marker_start );
			continue;
		}

		if ( $pos + 2 > $len ) {
			throw new \RuntimeException( 'Truncated length field for marker 0x' . dechex( $marker_id ) . '.' );
		}
		$seg_len = unpack( 'n', substr( $data, $pos, 2 ) )[1]; // Includes the 2 length bytes themselves.
		if ( $seg_len < 2 || $pos + $seg_len > $len ) {
			throw new \RuntimeException( 'Truncated segment for marker 0x' . dechex( $marker_id ) . '.' );
		}

		$payload = substr( $data, $pos + 2, $seg_len - 2 );
		if ( 0xE1 === $marker_id && str_starts_with( $payload, JPEG_XMP_SIGNATURE ) ) {
			$xmp_payload = substr( $payload, strlen( JPEG_XMP_SIGNATURE ) );
			$xmp_packets[] = $xmp_payload;
			++$stripped;
		} elseif ( is_jpeg_metadata_segment( $marker_id, $payload ) ) {
			if ( 0xE1 === $marker_id && str_starts_with( $payload, "Exif\x00\x00" ) ) {
				$exif_values = extract_allowed_exif_payload_values( $payload );
				if ( ! empty( $exif_values ) ) {
					$xmp_packets[] = build_xmp_packet_from_values( $exif_values );
				}
			} elseif ( 0xED === $marker_id ) {
				$iptc_values = extract_allowed_iptc_values( $payload );
				if ( ! empty( $iptc_values ) ) {
					$xmp_packets[] = build_xmp_packet_from_values( $iptc_values );
				}
			}
			++$stripped;
		} else {
			$out .= substr( $data, $marker_start, $pos - $marker_start );
			$out .= substr( $data, $pos, $seg_len );
		}

		$pos += $seg_len;
	}

	$merged_xmp = XmpFilter\merge_xmp_packets( $xmp_packets );
	if ( XmpFilter\EMPTY_PACKET !== $merged_xmp ) {
		$insert_at = null === $xmp_insert_position ? strlen( $out ) : $xmp_insert_position;
		$out       = substr( $out, 0, $insert_at )
			. build_filtered_xmp_segment( $merged_xmp )
			. substr( $out, $insert_at );
	}

	return [
		'data'              => $out,
		'stripped_segments' => $stripped,
	];
}

/**
 * Find the first marker after entropy-coded scan data.
 *
 * FF 00 is a stuffed literal FF byte and FF D0..D7 are restart markers; both
 * remain part of the scan. Any other non-zero marker ends the scan and is
 * returned to the main marker parser.
 *
 * @param string $data JPEG bytes.
 * @param int    $pos  First entropy-coded byte after the SOS header.
 * @param int    $len  Total byte length.
 * @return int Offset of the next marker's first FF byte.
 * @throws \RuntimeException If the scan reaches EOF without another marker.
 */
function find_jpeg_scan_end( string $data, int $pos, int $len ) : int {
	while ( $pos < $len ) {
		if ( "\xFF" !== $data[ $pos ] ) {
			++$pos;
			continue;
		}

		$marker_start = $pos;
		while ( $pos < $len && "\xFF" === $data[ $pos ] ) {
			++$pos;
		}
		if ( $pos >= $len ) {
			throw new \RuntimeException( 'Truncated JPEG scan (marker fill bytes ran to EOF).' );
		}

		$marker_id = ord( $data[ $pos ] );
		if ( 0x00 === $marker_id || ( $marker_id >= 0xD0 && $marker_id <= 0xD7 ) ) {
			++$pos;
			continue;
		}

		return $marker_start;
	}

	throw new \RuntimeException( 'Truncated JPEG scan (missing terminating marker).' );
}

/**
 * Read allowlisted EXIF values before the binary APP1 segment is removed.
 * The values are later written into a fresh XMP packet, never back into TIFF.
 *
 * @param string $jpeg Complete JPEG bytes.
 * @return array<string, string>
 */
function extract_allowed_exif_values( string $jpeg ) : array {
	if ( ! function_exists( 'exif_read_data' ) || ! str_contains( $jpeg, "Exif\x00\x00" ) ) {
		return [];
	}

	$tmp_path = tempnam( sys_get_temp_dir(), 'hmpc-exif-' );
	if ( false === $tmp_path ) {
		return [];
	}

	try {
		if ( false === file_put_contents( $tmp_path, $jpeg ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return [];
		}
		$sections = @exif_read_data( $tmp_path, null, true, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed/absent EXIF is expected.
	} finally {
		wp_delete_file( $tmp_path );
	}

	return is_array( $sections ) ? extract_allowed_values_from_exif_sections( $sections ) : [];
}

/**
 * Read allowlisted values from a standalone EXIF/TIFF payload, as stored in
 * a WebP EXIF chunk. Both the TIFF-header form and the optional JPEG-style
 * "Exif\0\0" prefix are accepted; the original binary is never rewritten.
 *
 * @param string $payload Raw EXIF chunk payload.
 * @return array<string, string>
 */
function extract_allowed_exif_payload_values( string $payload ) : array {
	if ( str_starts_with( $payload, "Exif\x00\x00" ) ) {
		$payload = substr( $payload, 6 );
	}
	if ( ! str_starts_with( $payload, "II\x2A\x00" ) && ! str_starts_with( $payload, "MM\x00\x2A" ) ) {
		return [];
	}

	assert_exif_orientation_is_normal( $payload );
	if ( ! function_exists( 'exif_read_data' ) ) {
		return [];
	}

	$tmp_path = tempnam( sys_get_temp_dir(), 'hmpc-exif-' );
	if ( false === $tmp_path ) {
		return [];
	}

	try {
		if ( false === file_put_contents( $tmp_path, $payload ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return [];
		}
		$sections = @exif_read_data( $tmp_path, null, true, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed EXIF is safely ignored.
	} finally {
		wp_delete_file( $tmp_path );
	}

	return is_array( $sections ) ? extract_allowed_values_from_exif_sections( $sections ) : [];
}

/**
 * Refuse to discard an EXIF rendering transform without normalizing pixels.
 * Read only the bounded IFD0 orientation entry, independent of ext-exif.
 *
 * @param string $tiff TIFF bytes after the optional EXIF signature.
 * @throws \RuntimeException If stripping could change display orientation.
 */
function assert_exif_orientation_is_normal( string $tiff ) : void {
	$length = strlen( $tiff );
	if ( $length < 8 ) {
		throw new \RuntimeException( 'Truncated EXIF header; orientation cannot be verified.' );
	}
	$little = 'II' === substr( $tiff, 0, 2 );
	$short  = $little ? 'v' : 'n';
	$long   = $little ? 'V' : 'N';
	$offset = unpack( $long, substr( $tiff, 4, 4 ) )[1];
	if ( $offset < 8 || $offset > $length - 2 ) {
		throw new \RuntimeException( 'Invalid EXIF directory; orientation cannot be verified.' );
	}
	$count = unpack( $short, substr( $tiff, $offset, 2 ) )[1];
	$offset += 2;
	if ( $count > intdiv( $length - $offset, 12 ) ) {
		throw new \RuntimeException( 'Truncated EXIF directory; orientation cannot be verified.' );
	}
	for ( $index = 0; $index < $count; ++$index, $offset += 12 ) {
		$tag = unpack( $short, substr( $tiff, $offset, 2 ) )[1];
		if ( 0x0112 !== $tag ) {
			continue;
		}
		$type        = unpack( $short, substr( $tiff, $offset + 2, 2 ) )[1];
		$value_count = unpack( $long, substr( $tiff, $offset + 4, 4 ) )[1];
		$orientation = unpack( $short, substr( $tiff, $offset + 8, 2 ) )[1];
		if ( 3 !== $type || 1 !== $value_count || 1 !== $orientation ) {
			throw new \RuntimeException( 'EXIF orientation requires pixel normalization before metadata can be removed. Normalize the original off-host and re-upload it.' );
		}
	}
}

/**
 * Map PHP's EXIF sections to the allowed fields (see Fields\conversion_targets()).
 *
 * @param array $sections Parsed EXIF sections.
 * @return array<string, string> Field ID => value.
 */
function extract_allowed_values_from_exif_sections( array $sections ) : array {
	$flat = [];
	foreach ( $sections as $section ) {
		if ( is_array( $section ) ) {
			$flat += $section;
		}
	}

	$values = [];
	foreach ( Fields\conversion_targets() as $field_id => $target ) {
		if ( empty( $target['exif'] ) ) {
			continue;
		}
		$value = first_scalar_metadata_value( $flat, $target['exif'] );
		if ( null !== $value ) {
			$values[ $field_id ] = $target['date'] ? normalize_metadata_date( $value ) : $value;
		}
	}

	return $values;
}

/**
 * Read allowlisted IPTC-IIM datasets from a Photoshop APP13 payload. A field
 * listing several datasets joins their first values in order (e.g. the
 * created date 2#055 followed by its time 2#060); it is skipped when the
 * first dataset is empty.
 *
 * @param string $payload APP13 payload.
 * @return array<string, string> Field ID => value.
 */
function extract_allowed_iptc_values( string $payload ) : array {
	if ( ! function_exists( 'iptcparse' ) ) {
		return [];
	}
	$iptc = @iptcparse( $payload ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed/absent IPTC is expected.
	if ( ! is_array( $iptc ) ) {
		return [];
	}

	$values = [];
	foreach ( Fields\conversion_targets() as $field_id => $target ) {
		$datasets = $target['iptc'];
		if ( empty( $datasets ) || empty( $iptc[ $datasets[0] ][0] ) ) {
			continue;
		}
		$value = trim( implode( '', array_map( static fn( string $dataset ) : string => (string) ( $iptc[ $dataset ][0] ?? '' ), $datasets ) ) );
		if ( '' !== $value ) {
			$values[ $field_id ] = $target['date'] ? normalize_metadata_date( $value ) : $value;
		}
	}

	return $values;
}

/**
 * Return the first non-empty scalar value for a list of metadata keys.
 *
 * @param array    $metadata Parsed metadata.
 * @param string[] $keys     Keys in precedence order.
 * @return string|null
 */
function first_scalar_metadata_value( array $metadata, array $keys ) : ?string {
	foreach ( $keys as $key ) {
		if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
			$value = sanitize_metadata_text( (string) $metadata[ $key ] );
			if ( '' !== $value ) {
				return $value;
			}
		}
	}
	return null;
}

/**
 * Convert metadata bytes to valid UTF-8/XML text and remove control bytes.
 *
 * @param string $value Raw metadata value.
 * @return string
 */
function sanitize_metadata_text( string $value ) : string {
	if ( 1 !== preg_match( '//u', $value ) ) {
		$converted = iconv( 'Windows-1252', 'UTF-8//IGNORE', $value );
		$value     = false === $converted ? '' : $converted;
	}
	$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
	return trim( $clean ?? '', " \t\n\r\0\x0B" );
}

/**
 * Normalize common EXIF/IPTC date shapes to an XMP-friendly ISO value.
 * Unknown shapes are retained verbatim as the source's allowed date value.
 *
 * @param string $date Source date.
 * @return string
 */
function normalize_metadata_date( string $date ) : string {
	$date = trim( $date );
	if ( preg_match( '/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $date, $matches ) ) {
		return "$matches[1]-$matches[2]-$matches[3]T$matches[4]:$matches[5]:$matches[6]";
	}
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})([+\-]\d{4})?$/', $date, $matches ) ) {
		$timezone = empty( $matches[7] ) ? '' : substr( $matches[7], 0, 3 ) . ':' . substr( $matches[7], 3, 2 );
		return "$matches[1]-$matches[2]-$matches[3]T$matches[4]:$matches[5]:$matches[6]$timezone";
	}
	return $date;
}

/**
 * Build a fresh XMP packet from values extracted from binary metadata. Each
 * value is written to its field's first XMP property.
 *
 * @param array<string, string> $values Field ID => value.
 * @return string
 */
function build_xmp_packet_from_values( array $values ) : string {
	$properties = [];

	foreach ( Fields\conversion_targets() as $field_id => $target ) {
		if ( empty( $values[ $field_id ] ) ) {
			continue;
		}
		[ $namespace, $local_name, $prefix ] = $target['xmp'];
		$value = sanitize_metadata_text( $values[ $field_id ] );
		// When several fields target the same XMP property, the first one wins.
		if ( '' === $value || isset( $properties[ $namespace . '|' . $local_name ] ) ) {
			continue;
		}
		$properties[ $namespace . '|' . $local_name ] = [
			'namespace'  => $namespace,
			'local_name' => $local_name,
			'prefix'     => $prefix,
			'value'      => $value,
			'lang'       => null,
		];
	}

	return empty( $properties ) ? XmpFilter\EMPTY_PACKET : XmpFilter\build_filtered_packet( array_values( $properties ), [] );
}

/**
 * Decide whether a length-prefixed JPEG segment is non-rendering metadata.
 * Unknown APP payloads fail closed; only positively identified structural or
 * color-management payloads survive.
 *
 * @param int    $marker_id JPEG marker byte without the 0xFF introducer.
 * @param string $payload   Segment payload without its length field.
 * @return bool
 */
function is_jpeg_metadata_segment( int $marker_id, string $payload ) : bool {
	if ( JPEG_COM_MARKER === $marker_id ) {
		return true;
	}
	if ( $marker_id < JPEG_APP_MARKER_MIN || $marker_id > JPEG_APP_MARKER_MAX ) {
		return false;
	}

	return match ( $marker_id ) {
		0xE0    => ! str_starts_with( $payload, "JFIF\x00" ) && ! str_starts_with( $payload, "JFXX\x00" ),
		0xE2    => ! str_starts_with( $payload, JPEG_ICC_SIGNATURE ),
		0xEE    => ! str_starts_with( $payload, 'Adobe' ),
		default => true,
	};
}

/**
 * Build a replacement APP1 segment containing only the allowlisted
 * XMP properties, or '' if nothing allowlisted was present (the segment
 * should simply be omitted in that case — unlike PDF's Stage 2, JPEG
 * segments aren't constrained to a fixed byte length, so there's no reason
 * to keep an empty XMP wrapper around).
 *
 * @param string $xmp_payload Raw XMP packet bytes (the segment payload after the XMP signature).
 * @return string A complete "\xFF\xE1" + length + signature + filtered-packet segment, or ''.
 */
function build_filtered_xmp_segment( string $xmp_payload ) : string {
	try {
		$filtered = XmpFilter\filter_xmp_packet( $xmp_payload );
	} catch ( \RuntimeException $e ) {
		return ''; // Unparseable — safest to drop it entirely, same as before this change.
	}

	if ( XmpFilter\EMPTY_PACKET === $filtered ) {
		return '';
	}

	$payload = JPEG_XMP_SIGNATURE . $filtered;
	$seg_len = strlen( $payload ) + 2;

	if ( $seg_len > 0xFFFF ) {
		return ''; // Pathologically large — segment length field is 16-bit; drop rather than corrupt.
	}

	return "\xFF\xE1" . pack( 'n', $seg_len ) . $payload;
}
