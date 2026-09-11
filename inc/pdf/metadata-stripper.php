<?php
/**
 * Stage 2 of PDF sanitization: filter all XMP Metadata streams in Stage 1's
 * output, including its rebuilt Catalog metadata and residual per-object XMP
 * carried over from embedded artwork.
 *
 * Objects are located via FPDI's own parser/cross-reference table — never by
 * regex over the whole file. A prior naive attempt (a single regex spanning
 * `obj...stream...endstream` over a ~10MB file) hit a PCRE limit, silently
 * returned null, and produced a corrupt 0-byte "clean" file. This
 * implementation instead: (1) uses the cross-reference table to get each
 * object's exact byte offset, (2) does a small bounded `strpos` from that
 * known offset to find the `stream` keyword, and (3) overwrites the stream's
 * content with a same-length replacement — never resizing anything, so no
 * xref/trailer rewrite is ever needed and no other object's byte offset
 * shifts. The replacement keeps only the allowlisted properties
 * (title, organisation, copyright, created date, version, and language — see
 * XmpFilter\filter_xmp_packet())
 * padded to fit, falling back to a fully empty packet when nothing
 * allowlisted was present or the filtered result doesn't fit the available
 * space.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Pdf;

use HM\MediaPiiCleaner\XmpFilter;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * Strip /Type /Metadata /Subtype /XML stream objects from a PDF's raw bytes.
 *
 * @param string $data Raw PDF bytes (expected to be an FPDI-rebuilt Stage 1
 *                      output — classic xref, no object streams).
 * @return array{data: string, stripped_count: int, skipped_filtered_count: int}
 * @throws \RuntimeException If the object table can't be parsed.
 */
function strip_metadata_streams( string $data ) : array {
	try {
		$parser = new PdfParser( StreamReader::createByString( $data ) );
		$xref   = $parser->getCrossReference();
		$size   = $xref->getSize();
	} catch ( \Throwable $e ) {
		throw new \RuntimeException( 'Unable to parse cross-reference table: ' . $e->getMessage(), 0, $e );
	}

	$patches         = []; // List of [contentStartOffset, contentLength].
	$skipped_filtered = 0;

	for ( $object_number = 1; $object_number < $size; $object_number++ ) {
		try {
			$object = $parser->getIndirectObject( $object_number );
		} catch ( \Throwable $e ) {
			continue; // Free/missing entry — nothing to do.
		}

		$value = $object->value ?? null;

		if ( ! ( $value instanceof PdfStream ) ) {
			continue;
		}

		$dict    = $value->value;
		$type    = PdfDictionary::get( $dict, 'Type' );
		$subtype = PdfDictionary::get( $dict, 'Subtype' );

		if ( ! ( $type instanceof PdfName ) || 'Metadata' !== $type->value ) {
			continue;
		}
		if ( ! ( $subtype instanceof PdfName ) || 'XML' !== $subtype->value ) {
			continue;
		}

		$filter = PdfDictionary::get( $dict, 'Filter' );
		if ( ! ( $filter instanceof PdfNull ) ) {
			// Compressed Metadata stream — do not attempt to touch compressed
			// bytes without decompressing/recompressing (out of scope for the
			// same-length in-place patch this function relies on for safety).
			++$skipped_filtered;
			continue;
		}

		$length = PdfType::resolve( PdfDictionary::get( $dict, 'Length' ), $parser );
		if ( ! ( $length instanceof PdfNumeric ) || $length->value <= 0 ) {
			continue;
		}
		$content_length = (int) $length->value;

		$object_offset = $xref->getOffsetFor( $object_number );
		$stream_kw_pos = strpos( $data, 'stream', $object_offset );
		if ( false === $stream_kw_pos ) {
			continue;
		}

		$content_start = $stream_kw_pos + strlen( 'stream' );
		// Per the PDF spec, the "stream" keyword is followed by CRLF or LF
		// (never a bare CR) before the content begins.
		if ( "\r\n" === substr( $data, $content_start, 2 ) ) {
			$content_start += 2;
		} elseif ( "\n" === substr( $data, $content_start, 1 ) ) {
			$content_start += 1;
		}

		$patches[] = [ $content_start, $content_length ];
	}

	foreach ( $patches as [ $start, $len ] ) {
		$original_content = substr( $data, $start, $len );
		$data             = substr_replace( $data, build_placeholder( $original_content, $len ), $start, $len );
	}

	return [
		'data'                   => $data,
		'stripped_count'         => count( $patches ),
		'skipped_filtered_count' => $skipped_filtered,
	];
}

/**
 * Build a same-length replacement for a Metadata stream's content: the
 * source packet filtered down to only the allowlisted properties (title,
 * organisation, copyright, created date, version, language), padded to fit
 * exactly — never a fully
 * empty packet unless nothing allowlisted was present or the filtered
 * result doesn't fit in the original stream's byte budget.
 *
 * @param string $original_content The stream's original raw bytes.
 * @param int    $target_length    Exact byte length the placeholder must occupy.
 * @return string
 */
function build_placeholder( string $original_content, int $target_length ) : string {
	if ( $target_length < strlen( XmpFilter\EMPTY_PACKET ) ) {
		return str_repeat( ' ', $target_length );
	}

	return XmpFilter\filter_xmp_packet_to_length( $original_content, $target_length );
}
