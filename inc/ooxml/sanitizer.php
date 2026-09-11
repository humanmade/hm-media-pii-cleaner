<?php
/**
 * OOXML (DOCX/XLSX/PPTX) document-properties sanitizer.
 *
 * An OOXML package is a ZIP archive; the equivalent of a PDF's /Info dict or
 * an image's XMP packet lives in two small, well-known package parts:
 * docProps/core.xml (Dublin Core + OPC "Core Properties" — title, creator,
 * lastModifiedBy, created/modified dates) and docProps/app.xml ("Extended
 * Properties" — which application/version produced the file, and Company).
 * A third, docProps/custom.xml, holds arbitrary author-defined key/value
 * pairs with no allowlisted field among them.
 *
 * Unlike the binary image/PDF formats elsewhere in this plugin, a ZIP
 * container has no absolute byte-offset structure to preserve — PHP's
 * ZipArchive correctly rewrites the central directory when a part's content
 * is replaced, so this operates one package part at a time rather than
 * needing the same-length-in-place-patch discipline PDF Stage 2 requires.
 *
 * Legacy binary Office formats (.doc/.xls/.ppt, Compound File Binary Format)
 * are a completely different container and are not covered — see
 * LIMITATIONS.md.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Ooxml;

use HM\MediaPiiCleaner\Fields;
use HM\MediaPiiCleaner\Limits;

const CORE_PROPERTIES_ENTRY   = 'docProps/core.xml';
const APP_PROPERTIES_ENTRY    = 'docProps/app.xml';
const CUSTOM_PROPERTIES_ENTRY = 'docProps/custom.xml';

const CP_NS      = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';
const DC_NS      = 'http://purl.org/dc/elements/1.1/';
const DCTERMS_NS = 'http://purl.org/dc/terms/';
const XSI_NS      = 'http://www.w3.org/2001/XMLSchema-instance';
const APP_NS      = 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties';
const CUSTOM_NS   = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';
const VT_NS        = 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';

const EMPTY_CUSTOM_PROPERTIES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
	. '<Properties xmlns="' . CUSTOM_NS . '" xmlns:vt="' . VT_NS . '"/>' . "\r\n";

/**
 * Strip disallowed metadata from an OOXML (.docx/.xlsx/.pptx) package's raw
 * bytes: docProps/core.xml and docProps/app.xml are rebuilt from only the
 * allowlisted values, docProps/custom.xml (arbitrary author-defined
 * properties — nothing on it is allowlisted) is emptied. Every other package
 * part (document content, styles, media, relationships) passes through
 * untouched.
 *
 * @param string $data Raw OOXML (ZIP) file contents.
 * @return array{data: string, stripped_parts: int}
 * @throws \RuntimeException If the package can't be safely opened/rewritten.
 */
function strip_ooxml_metadata( string $data ) : array {
	if ( ! class_exists( '\ZipArchive' ) ) {
		throw new \RuntimeException( 'The ZipArchive PHP extension is not available.' );
	}
	Limits\assert_data_processable( $data, HM_MEDIA_PII_CLEANER_OOXML_MIMES[0] );

	if ( '' === $data || "PK\x03\x04" !== substr( $data, 0, 4 ) ) {
		throw new \RuntimeException( 'Not a ZIP/OOXML package (bad local file header signature).' );
	}

	$tmp_path = wp_tempnam( 'hm-media-pii-cleaner-ooxml' );
	if ( ! is_string( $tmp_path ) || '' === $tmp_path ) {
		throw new \RuntimeException( 'Unable to allocate a temporary package file.' );
	}

	$zip = null;
	try {
		$written = file_put_contents( $tmp_path, $data, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( strlen( $data ) !== $written ) {
			throw new \RuntimeException( 'Unable to write a complete temporary copy of the package.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp_path ) ) {
			throw new \RuntimeException( 'Unable to open the package as a ZIP archive.' );
		}
		validate_archive( $zip );

		$stripped = 0;

		$core_xml = read_entry_capped( $zip, CORE_PROPERTIES_ENTRY );
		if ( null !== $core_xml ) {
			$filtered = filter_core_properties( $core_xml );
			if ( $filtered !== $core_xml ) {
				replace_entry( $zip, CORE_PROPERTIES_ENTRY, $filtered );
				++$stripped;
			}
		}

		$app_xml = read_entry_capped( $zip, APP_PROPERTIES_ENTRY );
		if ( null !== $app_xml ) {
			$filtered = filter_app_properties( $app_xml );
			if ( $filtered !== $app_xml ) {
				replace_entry( $zip, APP_PROPERTIES_ENTRY, $filtered );
				++$stripped;
			}
		}

		$custom_xml = read_entry_capped( $zip, CUSTOM_PROPERTIES_ENTRY );
		if ( null !== $custom_xml && EMPTY_CUSTOM_PROPERTIES !== $custom_xml ) {
			replace_entry( $zip, CUSTOM_PROPERTIES_ENTRY, EMPTY_CUSTOM_PROPERTIES );
			++$stripped;
		}

		if ( ! $zip->close() ) {
			throw new \RuntimeException( 'Failed to finalize the sanitized package.' );
		}
		$zip = null;

		$result = Limits\read_file( $tmp_path, HM_MEDIA_PII_CLEANER_OOXML_MIMES[0] );
	} finally {
		if ( $zip instanceof \ZipArchive ) {
			$zip->close();
		}
		wp_delete_file( $tmp_path );
	}

	return [
		'data'           => $result,
		'stripped_parts' => $stripped,
	];
}

/**
 * Validate ZIP metadata before any archive entry is decompressed.
 *
 * @param \ZipArchive $zip Open OOXML archive.
 * @throws \RuntimeException When an archive exceeds a resource budget or has
 *                           an ambiguous/unsafe entry layout.
 */
function validate_archive( \ZipArchive $zip ) : void {
	$entry_limit = max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_ENTRIES );
	$entry_count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native ZipArchive property.
	if ( $entry_count > $entry_limit ) {
		throw new \RuntimeException( "OOXML package contains more than $entry_limit entries." );
	}

	$entry_size_limit = max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_ENTRY_BYTES );
	$total_size_limit = max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_EXPANDED_BYTES );
	$property_limit   = max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_PROPERTY_BYTES );
	$ratio_limit      = max( 1, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_COMPRESSION_RATIO );
	$total_size       = 0;
	$seen             = [];
	$properties       = [];
	foreach ( [ CORE_PROPERTIES_ENTRY, APP_PROPERTIES_ENTRY, CUSTOM_PROPERTIES_ENTRY ] as $property_name ) {
		$properties[ strtolower( $property_name ) ] = $property_name;
	}

	for ( $index = 0; $index < $entry_count; $index++ ) {
		$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
		if ( false === $stat || ! isset( $stat['name'] ) || ! isset( $stat['size'] ) || ! isset( $stat['comp_size'] ) ) {
			throw new \RuntimeException( "Unable to inspect OOXML ZIP entry #$index." );
		}

		$name       = (string) $stat['name'];
		$normalized = normalize_entry_name( $name );
		$key        = strtolower( $normalized );
		if ( isset( $seen[ $key ] ) ) {
			throw new \RuntimeException( "OOXML package contains a duplicate or ambiguous entry name: $name." );
		}
		$seen[ $key ] = true;
		if ( isset( $properties[ $key ] ) && $normalized !== $properties[ $key ] ) {
			throw new \RuntimeException( "OOXML metadata property uses a non-canonical entry name: $name." );
		}

		$size            = (int) $stat['size'];
		$compressed_size = (int) $stat['comp_size'];
		if ( $size < 0 || $compressed_size < 0 ) {
			throw new \RuntimeException( "OOXML entry has an invalid size: $name." );
		}
		if ( isset( $properties[ $key ] ) && $size > $property_limit ) {
			throw new \RuntimeException( 'OOXML metadata property entry exceeds the ' . Limits\format_bytes( $property_limit ) . " limit: $name." );
		}
		if ( $size > $entry_size_limit ) {
			throw new \RuntimeException( 'OOXML entry exceeds the ' . Limits\format_bytes( $entry_size_limit ) . " expanded-size limit: $name." );
		}
		if ( $size > $total_size_limit - $total_size ) {
			throw new \RuntimeException( 'OOXML package exceeds the ' . Limits\format_bytes( $total_size_limit ) . ' total expanded-size limit.' );
		}
		$total_size += $size;

		if ( $size > 0 && ( 0 === $compressed_size || ( $size / $compressed_size ) > $ratio_limit ) ) {
			throw new \RuntimeException( "OOXML entry exceeds the maximum $ratio_limit:1 compression ratio: $name." );
		}
		if ( ! empty( $stat['encryption_method'] ) ) {
			throw new \RuntimeException( "Encrypted OOXML entries cannot be safely sanitized: $name." );
		}
	}
}

/**
 * Normalize and validate an OOXML part name without extracting it.
 *
 * @param string $name ZIP entry name.
 * @return string Normalized entry name.
 * @throws \RuntimeException When the name is unsafe or ambiguous.
 */
function normalize_entry_name( string $name ) : string {
	$normalized = str_replace( '\\', '/', $name );
	$segments   = explode( '/', $normalized );
	$last       = count( $segments ) - 1;
	$ambiguous  = false;
	foreach ( $segments as $index => $segment ) {
		if ( '.' === $segment || '..' === $segment || ( '' === $segment && $index !== $last ) ) {
			$ambiguous = true;
			break;
		}
	}

	if (
		'' === $normalized
		|| str_contains( $name, '\\' )
		|| str_contains( $normalized, "\0" )
		|| str_starts_with( $normalized, '/' )
		|| preg_match( '/^[a-z]:\//i', $normalized )
		|| $ambiguous
	) {
		throw new \RuntimeException( "OOXML package contains an unsafe entry name: $name." );
	}

	return $normalized;
}

/**
 * Read one known metadata entry through a strict decompressed-byte ceiling.
 *
 * @param \ZipArchive $zip  Open OOXML archive.
 * @param string      $name Entry name.
 * @return string|null Entry bytes, or null when the entry is absent.
 * @throws \RuntimeException When the stream cannot be read exactly and safely.
 */
function read_entry_capped( \ZipArchive $zip, string $name ) : ?string {
	$index = $zip->locateName( $name, \ZipArchive::FL_UNCHANGED );
	if ( false === $index ) {
		return null;
	}

	$stat  = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
	$limit = max( 0, (int) HM_MEDIA_PII_CLEANER_MAX_OOXML_PROPERTY_BYTES );
	if ( false === $stat || ! isset( $stat['size'] ) || (int) $stat['size'] > $limit ) {
		throw new \RuntimeException( "OOXML metadata property entry exceeds its read limit: $name." );
	}

	$stream = $zip->getStream( $name );
	if ( false === $stream ) {
		throw new \RuntimeException( "Unable to open OOXML metadata property entry: $name." );
	}

	$data = '';
	try {
		while ( ! feof( $stream ) && strlen( $data ) <= $limit ) {
			$remaining = ( $limit + 1 ) - strlen( $data );
			$chunk     = fread( $stream, min( 8192, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) {
				throw new \RuntimeException( "Unable to read OOXML metadata property entry: $name." );
			}
			$data .= $chunk;
		}
	} finally {
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	if ( strlen( $data ) > $limit || strlen( $data ) !== (int) $stat['size'] ) {
		throw new \RuntimeException( "OOXML metadata property entry did not match its declared safe size: $name." );
	}

	return $data;
}

/**
 * Replace an OOXML entry and fail if ZipArchive refuses the mutation.
 *
 * @param \ZipArchive $zip  Open OOXML archive.
 * @param string      $name Entry name.
 * @param string      $data Replacement bytes.
 * @throws \RuntimeException When the entry cannot be replaced.
 */
function replace_entry( \ZipArchive $zip, string $name, string $data ) : void {
	if ( ! $zip->addFromString( $name, $data ) ) {
		throw new \RuntimeException( "Unable to replace OOXML metadata property entry: $name." );
	}
}

/**
 * Filter docProps/core.xml down to the allowlist, rebuilt from
 * vetted scalar text values only — never by editing or cloning the source
 * DOM, which rules out the nested-disallowed-content smuggling class of bug
 * already found and fixed once in xmp-filter.php. A part that can't be
 * confidently parsed is replaced with an empty properties document rather
 * than left as-is — this plugin's job is removing metadata, so a parse
 * failure fails closed (nothing survives) rather than open (original stays).
 *
 * @param string $xml Raw docProps/core.xml content.
 * @return string Filtered XML.
 */
function filter_core_properties( string $xml ) : string {
	$allowed = Fields\ooxml_core_properties();
	$values  = extract_allowed_scalars( $xml, $allowed ) ?? [];

	$doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
		. '<cp:coreProperties xmlns:cp="' . CP_NS . '" xmlns:dc="' . DC_NS . '" xmlns:dcterms="' . DCTERMS_NS . '" xmlns:xsi="' . XSI_NS . '">';

	foreach ( $allowed as [ $ns, $local ] ) {
		$value = $values[ $ns ][ $local ] ?? null;
		if ( null === $value ) {
			continue;
		}
		$prefix    = Fields\OOXML_CORE_NAMESPACES[ $ns ];
		$type_attr = DCTERMS_NS === $ns ? ' xsi:type="dcterms:W3CDTF"' : '';
		$doc      .= "<$prefix:$local$type_attr>" . htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</$prefix:$local>";
	}

	$doc .= '</cp:coreProperties>';

	return $doc;
}

/**
 * Filter docProps/app.xml down to the allowed elements (by default only
 * Company, the organisation name). Application/AppVersion (software
 * identification) and everything else is exactly the kind of disclosure this
 * plugin removes. Same fail-closed-on-parse-failure behaviour as
 * filter_core_properties().
 *
 * @param string $xml Raw docProps/app.xml content.
 * @return string Filtered XML.
 */
function filter_app_properties( string $xml ) : string {
	$allowed = array_map( static fn( string $local ) : array => [ APP_NS, $local ], Fields\ooxml_app_properties() );
	$values  = extract_allowed_scalars( $xml, $allowed ) ?? [];

	$doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
		. '<Properties xmlns="' . APP_NS . '" xmlns:vt="' . VT_NS . '">';

	foreach ( $allowed as [ , $local ] ) {
		$value = $values[ APP_NS ][ $local ] ?? null;
		if ( null !== $value ) {
			$doc .= "<$local>" . htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . "</$local>";
		}
	}

	$doc .= '</Properties>';

	return $doc;
}

/**
 * Parse an OOXML properties-part XML document and extract the text content
 * of specific allowlisted elements. Same hardening as xmp-filter.php: no
 * external entities, no DTDs, and never trust an element with any non-text
 * child (that could smuggle disallowed nested content past the allowlist).
 *
 * @param string $xml       Raw XML content.
 * @param array  $allowlist List of [namespace_uri, local_name] pairs.
 * @return array<string, array<string, string>>|null Values keyed by
 *         [namespace_uri][local_name], or null if the document could not be
 *         safely parsed.
 */
function extract_allowed_scalars( string $xml, array $allowlist ) : ?array {
	if ( '' === trim( $xml ) ) {
		return null;
	}

	$previous_use_errors = libxml_use_internal_errors( true );

	try {
		$dom = new \DOMDocument();
		$dom->resolveExternals   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		$dom->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.

		if ( ! $dom->loadXML( $xml, LIBXML_NONET ) || ! $dom->documentElement ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
			return null;
		}
		if ( null !== $dom->doctype ) {
			return null;
		}

		$values = [];
		foreach ( $allowlist as [ $ns, $local ] ) {
			$nodes = $dom->documentElement->getElementsByTagNameNS( $ns, $local ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
			if ( 0 === $nodes->length ) {
				continue;
			}
			$text = scalar_text_or_null( $nodes->item( 0 ) );
			if ( null !== $text && '' !== trim( $text ) ) {
				$values[ $ns ][ $local ] = trim( $text );
			}
		}

		return $values;
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_use_errors );
	}
}

/**
 * Return an element's text content only if every child is text/CDATA — never
 * an element (which could carry disallowed nested content), comment, or
 * processing instruction.
 *
 * @param \DOMNode $node Candidate element.
 * @return string|null
 */
function scalar_text_or_null( \DOMNode $node ) : ?string {
	$text = '';
	foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
		if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
			$text .= $child->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
			continue;
		}
		return null;
	}

	return $text;
}
