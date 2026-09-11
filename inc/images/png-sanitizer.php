<?php
/**
 * Byte-level PNG metadata stripper.
 *
 * Deliberately does NOT decode/re-encode via GD — a decode/encode round trip
 * risks alpha-channel corruption (imagesavealpha() without a preceding
 * imagealphablending(false) is a well-known GD gotcha) and needlessly
 * converts indexed-palette PNGs to truecolor. Instead this walks the PNG
 * chunk structure and applies the metadata allowlist to ancillary chunks
 * (tEXt/zTXt/iTXt text keywords, eXIf raw EXIF, tIME modification
 * timestamps). Critical chunks and a narrow rendering-relevant ancillary
 * allowlist are retained; unknown ancillary chunks are removed because they
 * can carry arbitrary private metadata.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Images;

use HM\MediaPiiCleaner\Fields;
use HM\MediaPiiCleaner\XmpFilter;

const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

/**
 * Chunk types known to carry authoring/editor metadata rather than pixel
 * data or rendering-relevant information.
 */
const PNG_METADATA_CHUNK_TYPES = [ 'tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME' ];

/**
 * Public ancillary chunks that can affect color, transparency, animation,
 * physical layout, or decoding. Unknown/private ancillary chunks are not
 * retained because their semantics cannot be proven metadata-free.
 */
const PNG_RENDERING_ANCILLARY_CHUNK_TYPES = [
	'acTL',
	'fcTL',
	'fdAT', // APNG animation.
	'cHRM',
	'cICP',
	'gAMA',
	'iCCP',
	'mDCv',
	'cLLi',
	'sBIT',
	'sRGB', // Color/HDR.
	'bKGD',
	'hIST',
	'tRNS',
	'pHYs',
	'sPLT', // Rendering hints/transparency.
	'oFFs',
	'pCAL',
	'sCAL', // Physical placement/calibration.
	'sTER',
];

const PNG_XMP_KEYWORD           = 'XML:com.adobe.xmp';
const PNG_MAX_XMP_BYTES         = 4194304; // 4 MiB decompression ceiling for metadata-only content.

/**
 * Strip metadata-carrying ancillary chunks from a PNG's raw bytes.
 *
 * @param string $data Raw PNG file contents.
 * @return array{data: string, stripped_chunks: int}
 * @throws \RuntimeException If the chunk structure can't be safely walked.
 */
function strip_png_metadata( string $data ) : array {
	$len = strlen( $data );

	if ( $len < 8 || PNG_SIGNATURE !== substr( $data, 0, 8 ) ) {
		throw new \RuntimeException( 'Not a PNG file (bad signature).' );
	}

	$out     = substr( $data, 0, 8 );
	$pos     = 8;
	$stripped = 0;
	$seen_iend = false;

	while ( $pos < $len ) {
		if ( $pos + 8 > $len ) {
			throw new \RuntimeException( 'Truncated chunk header.' );
		}

		$chunk_length = unpack( 'N', substr( $data, $pos, 4 ) )[1];
		$chunk_type   = substr( $data, $pos + 4, 4 );
		$chunk_total  = 12 + $chunk_length; // length + type + data + crc.

		if ( $pos + $chunk_total > $len ) {
			throw new \RuntimeException( "Truncated $chunk_type chunk." );
		}

		$chunk_data = substr( $data, $pos + 8, $chunk_length );
		if ( 'iTXt' === $chunk_type && is_png_xmp_itxt_chunk( $chunk_data ) ) {
			$out .= build_filtered_png_xmp_chunk( $chunk_data );
			++$stripped;
		} elseif ( 'eXIf' === $chunk_type ) {
			$values = extract_allowed_exif_payload_values( $chunk_data );
			if ( ! empty( $values ) ) {
				$out .= build_png_xmp_packet_chunk( build_xmp_packet_from_values( $values ) );
			}
			++$stripped;
		} elseif ( is_allowed_text_chunk( $chunk_type, $chunk_data ) ) {
			$out .= substr( $data, $pos, $chunk_total );
		} elseif ( in_array( $chunk_type, PNG_METADATA_CHUNK_TYPES, true ) ) {
			++$stripped;
		} elseif ( is_png_critical_chunk( $chunk_type ) || in_array( $chunk_type, PNG_RENDERING_ANCILLARY_CHUNK_TYPES, true ) ) {
			$out .= substr( $data, $pos, $chunk_total );
		} else {
			++$stripped;
		}

		if ( 'IEND' === $chunk_type ) {
			$seen_iend = true;
		}

		$pos += $chunk_total;
	}

	if ( ! $seen_iend ) {
		throw new \RuntimeException( 'Missing IEND chunk.' );
	}

	return [
		'data'            => $out,
		'stripped_chunks' => $stripped,
	];
}

/**
 * Test the PNG ancillary bit without locale-sensitive case conversion.
 *
 * @param string $chunk_type Four-byte chunk type.
 * @throws \RuntimeException If the chunk type is not four ASCII letters.
 */
function is_png_critical_chunk( string $chunk_type ) : bool {
	if ( 4 !== strlen( $chunk_type ) || 1 !== preg_match( '/^[A-Za-z]{4}$/D', $chunk_type ) ) {
		throw new \RuntimeException( 'Invalid PNG chunk type.' );
	}

	return 0 === ( ord( $chunk_type[0] ) & 0x20 );
}

/**
 * Is this a tEXt/zTXt/iTXt chunk whose keyword is on the allowlist?
 * Binary eXIf is never copied through this path; allowed values are converted
 * separately into filtered XMP before the source chunk is removed.
 *
 * @param string $chunk_type Four-byte chunk type.
 * @param string $chunk_data Chunk's raw content (excluding the length/type/CRC framing).
 * @return bool
 */
function is_allowed_text_chunk( string $chunk_type, string $chunk_data ) : bool {
	if ( ! in_array( $chunk_type, [ 'tEXt', 'zTXt', 'iTXt' ], true ) ) {
		return false;
	}

	$separator = strpos( $chunk_data, "\x00" );
	if ( false === $separator ) {
		return false;
	}

	$keyword = substr( $chunk_data, 0, $separator );

	// Compared case-sensitively: the PNG spec's standard keywords are fixed, exact strings.
	return in_array( $keyword, Fields\png_keywords(), true );
}

/**
 * Does an iTXt chunk use PNG's registered XMP keyword?
 *
 * @param string $chunk_data Raw iTXt content.
 * @return bool
 */
function is_png_xmp_itxt_chunk( string $chunk_data ) : bool {
	$separator = strpos( $chunk_data, "\x00" );

	return false !== $separator && PNG_XMP_KEYWORD === substr( $chunk_data, 0, $separator );
}

/**
 * Replace a PNG XMP iTXt chunk with one containing only allowlisted XMP.
 * The replacement uses the PNG/XMP-recommended uncompressed representation
 * with empty language and translated-keyword fields. Invalid, oversized, or
 * empty-after-filtering packets are omitted rather than copied through.
 *
 * @param string $chunk_data Raw iTXt content.
 * @return string Complete iTXt chunk framing, or an empty string.
 */
function build_filtered_png_xmp_chunk( string $chunk_data ) : string {
	$xmp = extract_png_itxt_text( $chunk_data );
	if ( null === $xmp ) {
		return '';
	}

	return build_png_xmp_packet_chunk( $xmp );
}

/**
 * Filter raw XMP and wrap surviving properties in a PNG XMP iTXt chunk.
 *
 * @param string $xmp Raw XMP packet.
 * @return string Complete iTXt chunk framing, or an empty string.
 */
function build_png_xmp_packet_chunk( string $xmp ) : string {
	try {
		$filtered = XmpFilter\filter_xmp_packet( $xmp );
	} catch ( \RuntimeException $e ) {
		return '';
	}

	if ( XmpFilter\EMPTY_PACKET === $filtered ) {
		return '';
	}

	return build_png_chunk( 'iTXt', PNG_XMP_KEYWORD . "\x00\x00\x00\x00\x00" . $filtered );
}

/**
 * Decode an XMP iTXt text field, supporting both legal compression flags.
 *
 * @param string $chunk_data Raw iTXt content.
 * @return string|null Decoded text, or null for malformed/unsafe input.
 */
function extract_png_itxt_text( string $chunk_data ) : ?string {
	$keyword_end = strpos( $chunk_data, "\x00" );
	if ( false === $keyword_end || PNG_XMP_KEYWORD !== substr( $chunk_data, 0, $keyword_end ) ) {
		return null;
	}

	$offset = $keyword_end + 1;
	if ( $offset + 4 > strlen( $chunk_data ) ) {
		return null;
	}

	$compression_flag   = ord( $chunk_data[ $offset ] );
	$compression_method = ord( $chunk_data[ $offset + 1 ] );
	if ( $compression_flag > 1 || 0 !== $compression_method ) {
		return null;
	}

	$language_end = strpos( $chunk_data, "\x00", $offset + 2 );
	if ( false === $language_end ) {
		return null;
	}
	$translated_end = strpos( $chunk_data, "\x00", $language_end + 1 );
	if ( false === $translated_end ) {
		return null;
	}

	$text = substr( $chunk_data, $translated_end + 1 );
	if ( 1 === $compression_flag ) {
		$text = @gzuncompress( $text, PNG_MAX_XMP_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed compressed metadata is safely dropped.
		if ( false === $text ) {
			return null;
		}
	}

	return strlen( $text ) <= PNG_MAX_XMP_BYTES ? $text : null;
}

/**
 * Build a PNG chunk with a correct CRC.
 *
 * @param string $chunk_type Four-byte chunk type.
 * @param string $chunk_data Chunk payload.
 * @return string Complete PNG chunk.
 */
function build_png_chunk( string $chunk_type, string $chunk_data ) : string {
	$crc = hash( 'crc32b', $chunk_type . $chunk_data, true );

	return pack( 'N', strlen( $chunk_data ) ) . $chunk_type . $chunk_data . $crc;
}
