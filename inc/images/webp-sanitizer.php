<?php
/**
 * Byte-level WebP metadata stripper.
 *
 * Deliberately does NOT decode/re-encode via GD/Imagick — re-encoding a
 * lossless WebP is a direct visual-quality regression, and Imagick cannot be
 * assumed available on every host. WebP is a
 * RIFF container; this walks its chunk structure and replaces EXIF/XMP with
 * filtered XMP where allowed values survive. Only defined rendering chunks
 * are retained; unknown top-level and per-frame chunks are removed because
 * they can carry arbitrary application metadata.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Images;

use HM\MediaPiiCleaner\XmpFilter;

const WEBP_METADATA_CHUNK_TYPES = [ 'EXIF', 'XMP ' ]; // 'XMP ' includes the trailing space per the FourCC spec.
const WEBP_RENDERING_CHUNK_TYPES = [ 'VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ANIM', 'ANMF', 'ICCP' ];
const WEBP_FRAME_CHUNK_TYPES     = [ 'ALPH', 'VP8 ', 'VP8L' ];

/**
 * Strip EXIF/XMP metadata chunks from a WebP's raw bytes.
 *
 * @param string $data Raw WebP file contents.
 * @return array{data: string, stripped_chunks: int}
 * @throws \RuntimeException If the RIFF structure can't be safely walked.
 */
function strip_webp_metadata( string $data ) : array {
	$len = strlen( $data );

	if ( $len < 12 || 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
		throw new \RuntimeException( 'Not a WebP file (bad RIFF/WEBP header).' );
	}

	$chunks      = [];
	$xmp_packets = [];
	$pos         = 12;
	$stripped    = 0;

	while ( $pos < $len ) {
		if ( $pos + 8 > $len ) {
			throw new \RuntimeException( 'Truncated chunk header.' );
		}

		$fourcc       = substr( $data, $pos, 4 );
		$chunk_length = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1]; // RIFF is little-endian.
		$padding      = $chunk_length % 2; // Chunks are padded to an even length.
		$chunk_total  = 8 + $chunk_length + $padding;

		if ( $pos + $chunk_total > $len ) {
			throw new \RuntimeException( "Truncated $fourcc chunk." );
		}

		$chunk_data = substr( $data, $pos + 8, $chunk_length );
		$keep       = in_array( $fourcc, WEBP_RENDERING_CHUNK_TYPES, true );
		$raw        = substr( $data, $pos, $chunk_total );
		if ( 'ANMF' === $fourcc ) {
			$frame_result = sanitize_webp_anmf_payload( $chunk_data );
			$chunk_data   = $frame_result['data'];
			$raw          = build_webp_chunk( 'ANMF', $chunk_data );
			$stripped    += $frame_result['stripped_chunks'];
		}
		$chunks[] = [
			'fourcc' => $fourcc,
			'data'   => $chunk_data,
			'raw'    => $raw,
			'keep'   => $keep,
		];

		if ( 'XMP ' === $fourcc ) {
			$xmp_packets[] = $chunk_data;
			++$stripped;
		} elseif ( 'EXIF' === $fourcc ) {
			$values = extract_allowed_exif_payload_values( $chunk_data );
			if ( ! empty( $values ) ) {
				$xmp_packets[] = build_xmp_packet_from_values( $values );
			}
			++$stripped;
		} elseif ( ! $keep ) {
			++$stripped;
		}

		$pos += $chunk_total;
	}

	$merged_xmp     = XmpFilter\merge_xmp_packets( $xmp_packets );
	$xmp_chunk      = XmpFilter\EMPTY_PACKET === $merged_xmp ? '' : build_filtered_xmp_chunk( $merged_xmp );
	$emitted_xmp    = false;
	$out            = 'WEBP';

	foreach ( $chunks as $chunk ) {
		if ( in_array( $chunk['fourcc'], WEBP_METADATA_CHUNK_TYPES, true ) ) {
			if ( ! $emitted_xmp && '' !== $xmp_chunk ) {
				$out         .= $xmp_chunk;
				$emitted_xmp = true;
			}
			continue;
		}
		if ( ! $chunk['keep'] ) {
			continue;
		}

		if ( 'VP8X' === $chunk['fourcc'] ) {
			$out .= build_webp_chunk( 'VP8X', update_webp_vp8x_metadata_flags( $chunk['data'], '' !== $xmp_chunk ) );
		} else {
			$out .= $chunk['raw'];
		}
	}

	// RIFF's size field is "WEBP" + all chunks (i.e. total length minus the
	// 8-byte "RIFF"+size header itself) — recompute it since chunk removal
	// changed the total length. No other offsets exist in this format.
	$riff_size = pack( 'V', strlen( $out ) );

	return [
		'data'            => 'RIFF' . $riff_size . $out,
		'stripped_chunks' => $stripped,
	];
}

/**
 * Remove unknown chunks appended to an animated WebP frame.
 *
 * ANMF begins with a fixed 16-byte frame header followed by padded RIFF
 * chunks. Only alpha and image bitstream chunks affect the rendered frame.
 *
 * @param string $payload ANMF payload.
 * @return array{data: string, stripped_chunks: int}
 * @throws \RuntimeException If the frame structure is truncated.
 */
function sanitize_webp_anmf_payload( string $payload ) : array {
	$len = strlen( $payload );
	if ( $len < 16 ) {
		throw new \RuntimeException( 'Invalid ANMF chunk length.' );
	}

	$out      = substr( $payload, 0, 16 );
	$pos      = 16;
	$stripped = 0;

	while ( $pos < $len ) {
		if ( $pos + 8 > $len ) {
			throw new \RuntimeException( 'Truncated ANMF frame chunk header.' );
		}
		$fourcc       = substr( $payload, $pos, 4 );
		$chunk_length = unpack( 'V', substr( $payload, $pos + 4, 4 ) )[1];
		$chunk_total  = 8 + $chunk_length + ( $chunk_length % 2 );
		if ( $pos + $chunk_total > $len ) {
			throw new \RuntimeException( "Truncated ANMF $fourcc chunk." );
		}

		if ( in_array( $fourcc, WEBP_FRAME_CHUNK_TYPES, true ) ) {
			$out .= substr( $payload, $pos, $chunk_total );
		} else {
			++$stripped;
		}
		$pos += $chunk_total;
	}

	return [
		'data'            => $out,
		'stripped_chunks' => $stripped,
	];
}

/**
 * Build a replacement 'XMP ' RIFF chunk containing only the
 * allowlisted properties, or '' if nothing allowlisted was present. Binary
 * EXIF is removed separately after its allowed semantics are converted to XMP.
 *
 * @param string $chunk_data Raw XMP packet bytes (the chunk's payload).
 * @return string A complete "XMP " + length + (even-padded) packet chunk, or ''.
 */
function build_filtered_xmp_chunk( string $chunk_data ) : string {
	try {
		$filtered = XmpFilter\filter_xmp_packet( $chunk_data );
	} catch ( \RuntimeException $e ) {
		return ''; // Unparseable — safest to drop it entirely, same as before this change.
	}

	if ( XmpFilter\EMPTY_PACKET === $filtered ) {
		return '';
	}

	$length  = strlen( $filtered );
	$padding = $length % 2 ? "\x00" : '';

	return 'XMP ' . pack( 'V', $length ) . $filtered . $padding;
}

/**
 * Update VP8X's EXIF/XMP presence bits after replacing binary EXIF with XMP.
 * Other feature flags and canvas dimensions remain byte-for-byte unchanged.
 *
 * @param string $chunk_data VP8X payload.
 * @param bool   $has_xmp    Whether the sanitized container has an XMP chunk.
 * @return string Updated VP8X payload.
 * @throws \RuntimeException If the VP8X payload is malformed.
 */
function update_webp_vp8x_metadata_flags( string $chunk_data, bool $has_xmp ) : string {
	if ( 10 !== strlen( $chunk_data ) ) {
		throw new \RuntimeException( 'Invalid VP8X chunk length.' );
	}

	$flags  = ord( $chunk_data[0] );
	$flags &= ~0x08; // Original EXIF chunk is always removed.
	$flags  = $has_xmp ? $flags | 0x04 : $flags & ~0x04;

	return chr( $flags ) . substr( $chunk_data, 1 );
}

/**
 * Build a RIFF/WebP chunk with even-byte padding.
 *
 * @param string $fourcc FourCC identifier.
 * @param string $data   Chunk payload.
 * @return string Complete chunk.
 */
function build_webp_chunk( string $fourcc, string $data ) : string {
	$padding = strlen( $data ) % 2 ? "\x00" : '';

	return $fourcc . pack( 'V', strlen( $data ) ) . $data . $padding;
}
