<?php
/**
 * ISOBMFF (MP4 / QuickTime .mov) metadata stripper.
 *
 * MP4 and QuickTime container files are a tree of length-prefixed "boxes"
 * (ISO/IEC 14496-12; QuickTime's original format is a structural superset of
 * the same idea, so one parser covers both). Authoring/software metadata —
 * encoder name, camera make/model, GPS location, free-text titles/comments —
 * lives in `udta` ("user data") boxes and an optional `meta` box, found
 * inside `moov` and per-track `trak` containers. Actual audio/video sample
 * bytes live in one or more `mdat` boxes, and every sample-location table
 * (`stco`/`co64`, inside `moov`) records ABSOLUTE FILE OFFSETS into `mdat` —
 * so any edit that shifts a byte's position *before* the end of the last
 * `mdat` box would silently invalidate those offsets and corrupt playback.
 *
 * This only ever rewrites content positioned *after* the last `mdat` box's
 * end, which is the layout common encoders such as HandBrake produce by
 * default (`moov` last). A "fast-start" file (`moov`
 * before `mdat`, used to let a player start progressive-download playback
 * before the whole file arrives) would need every `stco`/`co64` entry
 * patched by the exact size delta to stay safe; this plugin does not
 * implement that and flags such files for manual review rather than risk
 * silently corrupting playback — the same "flag the complex case rather
 * than gamble" precedent as this plugin's PDF compressed-metadata-stream
 * handling.
 *
 * Unlike every other format in this plugin, no attempt is made to preserve
 * an allowlisted subset of video metadata (title/date/etc): real-world files
 * mix at least two different, competing metadata encoding conventions
 * inside a single `udta` tree (QuickTime "classic" atoms and
 * iTunes-style `meta`/`ilst` atoms), with no single reliable field mapping —
 * the same "no safe, reliable mapping exists" reasoning already applied to
 * GIF elsewhere in this plugin. See LIMITATIONS.md.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Video;

/** Box types wholesale-dropped wherever found within a rewritable region. */
const METADATA_BOX_TYPES = [ 'udta', 'meta' ];

/** Container box types that can legally hold udta/meta and are recursed into. */
const CONTAINER_BOX_TYPES = [ 'moov', 'trak' ];

/**
 * Strip udta/meta metadata boxes from an ISOBMFF (MP4/QuickTime) file's raw
 * bytes, without touching anything positioned at or before the end of the
 * last mdat box.
 *
 * @param string $data Raw MP4/QuickTime file contents.
 * @return array{data: string, stripped_boxes: int}
 * @throws \RuntimeException If the file isn't a supported, safely-editable layout.
 */
function strip_video_metadata( string $data ) : array {
	$len = strlen( $data );

	if ( $len < 8 ) {
		throw new \RuntimeException( 'File too small to be a valid ISOBMFF container.' );
	}

	$top_boxes = read_boxes( $data, 0, $len );

	if ( empty( $top_boxes ) || 'ftyp' !== $top_boxes[0]['type'] ) {
		throw new \RuntimeException( 'Not an ISOBMFF file (missing leading ftyp box).' );
	}

	$mdat_end = null;

	foreach ( $top_boxes as $box ) {
		if ( 'moof' === $box['type'] || 'mfra' === $box['type'] ) {
			throw new \RuntimeException( 'Fragmented MP4 is not supported for metadata stripping — flagged for manual review.' );
		}
		if ( 'mdat' === $box['type'] ) {
			$mdat_end = max( $mdat_end ?? 0, $box['start'] + $box['size'] );
		}
	}

	if ( null === $mdat_end ) {
		throw new \RuntimeException( 'No mdat box found.' );
	}

	$rewritable_top_level_types = array_merge( [ 'moov' ], METADATA_BOX_TYPES );

	foreach ( $top_boxes as $box ) {
		if ( in_array( $box['type'], $rewritable_top_level_types, true ) && $box['start'] < $mdat_end ) {
			throw new \RuntimeException( 'moov/udta/meta is positioned before the end of sample data (fast-start layout) — sample offset tables would need patching, which this plugin does not implement. Flagged for manual review.' );
		}
	}

	$out      = '';
	$stripped = 0;

	foreach ( $top_boxes as $box ) {
		if ( in_array( $box['type'], METADATA_BOX_TYPES, true ) ) {
			++$stripped;
			continue; // Top-level udta/meta (already confirmed safe to touch above): dropped entirely.
		}
		if ( 'moov' === $box['type'] ) {
			[ $rebuilt, $removed ] = rebuild_box( $data, $box );
			$out      .= $rebuilt;
			$stripped += $removed;
		} else {
			$out .= substr( $data, $box['start'], $box['size'] );
		}
	}

	// Self-check: sample data must be byte-for-byte identical before and
	// after. This should always hold by construction (only content at/after
	// $mdat_end is ever rewritten), but the stakes of getting this wrong
	// (corrupted, unplayable video) are high enough to verify rather than
	// only assert — same "verify, don't just trust the design" standard
	// applied everywhere else in this plugin.
	$out_boxes = read_boxes( $out, 0, strlen( $out ) );
	if ( mdat_digest( $data, $top_boxes ) !== mdat_digest( $out, $out_boxes ) ) {
		throw new \RuntimeException( 'Internal error: sample data changed while stripping metadata (aborted, nothing written).' );
	}

	return [
		'data'           => $out,
		'stripped_boxes' => $stripped,
	];
}

/**
 * Calculate a length and digest for every top-level mdat box, in order.
 *
 * Hashing bounded chunks avoids materializing two additional copies of all
 * video sample data solely for the integrity comparison.
 *
 * @param string $data      Full file bytes.
 * @param array  $top_boxes Top-level box descriptors, per read_boxes().
 * @return array{length: int, sha256: string}
 */
function mdat_digest( string $data, array $top_boxes ) : array {
	$context = hash_init( 'sha256' );
	$length  = 0;
	$chunk   = 1024 * 1024;

	foreach ( $top_boxes as $box ) {
		if ( 'mdat' !== $box['type'] ) {
			continue;
		}

		$length += $box['size'];
		$end     = $box['start'] + $box['size'];
		for ( $offset = $box['start']; $offset < $end; $offset += $chunk ) {
			hash_update( $context, substr( $data, $offset, min( $chunk, $end - $offset ) ) );
		}
	}

	return [
		'length' => $length,
		'sha256' => hash_final( $context ),
	];
}

/**
 * Parse the direct-child boxes within [start, end).
 *
 * @param string $data  Full file bytes.
 * @param int    $start Region start offset.
 * @param int    $end   Region end offset.
 * @return array<int, array{type: string, start: int, size: int, header_size: int}>
 * @throws \RuntimeException On a truncated or invalid box header.
 */
function read_boxes( string $data, int $start, int $end ) : array {
	$boxes = [];
	$pos   = $start;

	while ( $pos < $end ) {
		if ( $pos + 8 > $end ) {
			throw new \RuntimeException( 'Truncated box header.' );
		}

		$size        = unpack( 'N', substr( $data, $pos, 4 ) )[1];
		$type        = substr( $data, $pos + 4, 4 );
		$header_size = 8;

		if ( 1 === $size ) {
			if ( $pos + 16 > $end ) {
				throw new \RuntimeException( "Truncated 64-bit size header for $type." );
			}
			$high        = unpack( 'N', substr( $data, $pos + 8, 4 ) )[1];
			$low         = unpack( 'N', substr( $data, $pos + 12, 4 ) )[1];
			$size        = ( $high << 32 ) | $low;
			$header_size = 16;
		} elseif ( 0 === $size ) {
			$size = $end - $pos; // Box extends to the end of the enclosing region.
		}

		if ( $size < $header_size || $pos + $size > $end ) {
			throw new \RuntimeException( "Invalid or truncated $type box." );
		}

		$boxes[] = [
			'type'        => $type,
			'start'       => $pos,
			'size'        => $size,
			'header_size' => $header_size,
		];

		$pos += $size;
	}

	return $boxes;
}

/**
 * Rebuild a container box's complete bytes, recursively dropping any
 * udta/meta descendant and patching this box's (and every ancestor's)
 * declared size to match. Only ever called on boxes already confirmed to
 * start at or after the last mdat box's end.
 *
 * @param string $data Full file bytes.
 * @param array  $box  Box descriptor, per read_boxes().
 * @return array{0: string, 1: int} Rebuilt bytes, and the number of
 *                                   metadata boxes dropped within it.
 */
function rebuild_box( string $data, array $box ) : array {
	if ( ! in_array( $box['type'], CONTAINER_BOX_TYPES, true ) ) {
		// Leaf/unrecognised box: copy through byte-for-byte untouched.
		return [ substr( $data, $box['start'], $box['size'] ), 0 ];
	}

	$body_start = $box['start'] + $box['header_size'];
	$body_end   = $box['start'] + $box['size'];
	$children   = read_boxes( $data, $body_start, $body_end );

	$rebuilt_body = '';
	$removed      = 0;

	foreach ( $children as $child ) {
		if ( in_array( $child['type'], METADATA_BOX_TYPES, true ) ) {
			++$removed;
			continue; // Dropped entirely — not copied to the rebuilt body.
		}

		[ $child_bytes, $child_removed ] = rebuild_box( $data, $child );
		$rebuilt_body .= $child_bytes;
		$removed      += $child_removed;
	}

	return [ pack_box_header( $box, strlen( $rebuilt_body ) ) . $rebuilt_body, $removed ];
}

/**
 * Build a box header for a rebuilt box, preserving its original 32-bit vs
 * 64-bit size-field form.
 *
 * @param array $box         Original box descriptor (for type/header_size).
 * @param int   $body_length New body length (excluding the header itself).
 * @return string
 * @throws \RuntimeException If a 32-bit header can't represent the new size.
 */
function pack_box_header( array $box, int $body_length ) : string {
	$new_size = $box['header_size'] + $body_length;

	if ( 16 === $box['header_size'] ) {
		return pack( 'N', 1 ) . $box['type'] . pack( 'J', $new_size );
	}

	if ( $new_size > 0xFFFFFFFF ) {
		throw new \RuntimeException( 'Rebuilt box exceeds the 32-bit size field it started with.' );
	}

	return pack( 'N', $new_size ) . $box['type'];
}
