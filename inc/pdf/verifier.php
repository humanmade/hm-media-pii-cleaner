<?php
/**
 * Mandatory verification gate for sanitized PDFs. A file is only ever
 * treated as "sanitized" if it passes every check here — never inferred from
 * the absence of expected byte patterns alone (a corrupt/truncated output
 * would also show "no metadata strings found").
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Pdf;

use HM\MediaPiiCleaner\Fields;

/**
 * Namespace/field tokens unique to real authoring-tool XMP metadata — never
 * present in this plugin's own filtered/empty XMP replacement (see
 * XmpFilter\filter_xmp_packet(), which only ever keeps the documented
 * dc:/xmp:/xmpMM: allowlist) or in the sanitized Info dictionary, and not
 * the kind of text that occurs in ordinary (compressed) page content by
 * coincidence.
 */
const RESIDUAL_METADATA_TOKENS = [
	'illustrator:',
	'photoshop:',
	'xmpMM:DocumentID',
	'xmpMM:InstanceID',
	'pdfx:CreatorVersion',
	'dc:creator',
];

/**
 * Verify a Stage 1 + Stage 2 sanitized PDF before it's trusted as clean.
 *
 * @param string $source_path       Path to the original (pre-sanitization) PDF.
 * @param string $stage1_data       Stage 1 (FPDI rebuild) output bytes.
 * @param string $final_data        Stage 2 (metadata-stream-stripped) output bytes.
 * @return array{ok: bool, reason: string}
 */
function verify( string $source_path, string $stage1_data, string $final_data ) : array {
	if ( '' === $final_data || '%PDF-' !== substr( $final_data, 0, 5 ) ) {
		return fail( 'Output is empty or missing the %PDF- header (possible corruption).' );
	}

	if ( strlen( $final_data ) !== strlen( $stage1_data ) ) {
		// Stage 2 only ever does same-length in-place patches — any length
		// change means something unexpected happened.
		return fail( 'Stage 2 output length does not match Stage 1 output length.' );
	}

	// Residual checks follow the active allowlist: a property a developer has
	// allowed through the hm_media_pii_cleaner_allowed_fields filter is
	// expected in the output and must not flag (and quarantine) the file.
	$fields         = Fields\get_fields();
	$xmp_properties = Fields\xmp_properties( $fields );
	$info_keys      = Fields\pdf_info_keys( $fields );

	if ( ! is_xmp_token_allowed( 'xmp:CreatorTool', $xmp_properties )
		&& preg_match( '/<xmp:CreatorTool>(?!<\/xmp:CreatorTool>)/', $final_data )
	) {
		return fail( 'Residual xmp:CreatorTool value found.' );
	}

	// Allowed /Info keys are passed through from the source document.
	// Producer is never populated by this pipeline and never allowed, so any
	// non-empty value there means something leaked through unexpectedly.
	foreach ( [ 'Author', 'Subject', 'Keywords', 'Producer' ] as $info_key ) {
		if ( ! in_array( $info_key, $info_keys, true ) && preg_match( '/\/' . $info_key . '\s*\((?!\))/', $final_data ) ) {
			return fail( 'Residual non-empty Info dictionary field found.' );
		}
	}

	foreach ( RESIDUAL_METADATA_TOKENS as $token ) {
		if ( is_xmp_token_allowed( $token, $xmp_properties ) ) {
			continue;
		}
		if ( false !== strpos( $final_data, $token ) ) {
			return fail( "Residual metadata token found: $token" );
		}
	}

	try {
		$source_pages = count_pages( $source_path );
	} catch ( \RuntimeException $e ) {
		return fail( 'Could not re-count source page count: ' . $e->getMessage() );
	}

	$tmp_path = wp_tempnam( 'hm-media-pii-cleaner-verify.pdf' );
	file_put_contents( $tmp_path, $final_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	try {
		$output_pages = count_pages( $tmp_path );
	} catch ( \RuntimeException $e ) {
		wp_delete_file( $tmp_path );
		return fail( 'Sanitized output does not re-parse as a valid PDF: ' . $e->getMessage() );
	}

	wp_delete_file( $tmp_path );

	if ( $source_pages !== $output_pages ) {
		return fail( "Page count mismatch: source had $source_pages, output has $output_pages." );
	}

	return [
		'ok'     => true,
		'reason' => '',
	];
}

/**
 * Is a residual-token check covered by an allowed XMP property? A token is
 * either a whole prefix ("photoshop:") or a qualified name ("dc:creator"),
 * matched against the prefix each allowed property is written with.
 *
 * @param string $token          Residual token.
 * @param array  $xmp_properties Allowed [namespace, local name, prefix] list.
 * @return bool
 */
function is_xmp_token_allowed( string $token, array $xmp_properties ) : bool {
	[ $token_prefix, $token_local ] = array_pad( explode( ':', $token, 2 ), 2, '' );

	foreach ( $xmp_properties as [ , $local_name, $prefix ] ) {
		if ( $prefix === $token_prefix && ( '' === $token_local || $local_name === $token_local ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a failed verification result.
 *
 * @param string $reason Human-readable failure reason.
 * @return array{ok: bool, reason: string}
 */
function fail( string $reason ) : array {
	return [
		'ok'     => false,
		'reason' => $reason,
	];
}
