<?php
/**
 * Allowed metadata fields: the single source of truth for which metadata
 * survives sanitization, and how each field maps onto each file format.
 *
 * Developers can add, remove or modify fields with the
 * `hm_media_pii_cleaner_allowed_fields` filter. Every mapping is validated
 * strictly; anything malformed is dropped (never kept) and reported through
 * _doing_it_wrong(). See README.md for the mapping format.
 *
 * The filter is resolved on every call rather than cached, so a policy change
 * applies immediately and tests can vary it per case.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Fields;

const FILTER = 'hm_media_pii_cleaner_allowed_fields';

const DC_NS       = 'http://purl.org/dc/elements/1.1/';
const DCTERMS_NS  = 'http://purl.org/dc/terms/';
const XMP_NS      = 'http://ns.adobe.com/xap/1.0/';
const XMP_MM_NS   = 'http://ns.adobe.com/xap/1.0/mm/';
const OOXML_CP_NS = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

/**
 * Namespaces an XMP mapping may never target: they carry the packet structure
 * itself, so allowing them would let arbitrary source content through.
 */
const RESERVED_XMP_NAMESPACES = [
	'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
	'adobe:ns:meta/',
	'http://www.w3.org/XML/1998/namespace',
	'http://www.w3.org/2000/xmlns/',
];
const RESERVED_XMP_PREFIXES = [ 'rdf', 'x', 'xml', 'xmlns' ];

/**
 * PDF /Info keys the rebuild can pass through. FPDF writes exactly these
 * (CreationDate through a same-length swap, see Pdf\apply_source_creation_date()).
 * Producer is never kept: it always identifies the rebuild library.
 */
const PDF_INFO_KEYS = [ 'Title', 'Author', 'Subject', 'Keywords', 'Creator', 'CreationDate' ];

/** OOXML core-properties namespaces and the prefix each is written with. */
const OOXML_CORE_NAMESPACES = [
	DC_NS       => 'dc',
	DCTERMS_NS  => 'dcterms',
	OOXML_CP_NS => 'cp',
];

/** PNG's registered XMP keyword is always routed through the XMP filter, never copied raw. */
const PNG_XMP_KEYWORD = 'XML:com.adobe.xmp';

const MAPPING_KEYS = [ 'xmp', 'exif', 'iptc', 'png', 'pdf_info', 'ooxml_core', 'ooxml_app', 'image_meta', 'date' ];

/**
 * The built-in allowlist: title, organisation, copyright notice,
 * publication/created date, document version and language.
 *
 * Per-field mapping keys (all optional):
 * - xmp:        [ namespace, local name, prefix ] properties kept in XMP (JPEG, WebP, PNG, SVG, PDF).
 *               The first entry is also where EXIF/IPTC values are written when converted.
 * - exif:       EXIF tag names read, in precedence order, before binary EXIF is removed.
 * - iptc:       IPTC-IIM dataset codes whose first values are joined in order (e.g. date + time).
 * - png:        PNG tEXt/zTXt/iTXt keywords kept as-is.
 * - pdf_info:   PDF /Info keys passed through (see PDF_INFO_KEYS).
 * - ooxml_core: [ namespace, local name ] elements kept in docProps/core.xml.
 * - ooxml_app:  Element names kept in docProps/app.xml.
 * - image_meta: WordPress attachment image_meta keys kept.
 * - date:       true when EXIF/IPTC values should be normalized to an ISO 8601 date.
 *
 * @return array<string, array>
 */
function default_fields() : array {
	return [
		'title'        => [
			'xmp'        => [ [ DC_NS, 'title', 'dc' ] ],
			'exif'       => [ 'DocumentName', 'ImageDescription', 'XPTitle' ],
			'iptc'       => [ '2#005' ],
			'png'        => [ 'Title' ],
			'pdf_info'   => [ 'Title' ],
			'ooxml_core' => [ [ DC_NS, 'title' ] ],
			'image_meta' => [ 'title' ],
		],
		'organisation' => [
			'xmp'       => [ [ DC_NS, 'publisher', 'dc' ] ],
			'ooxml_app' => [ 'Company' ],
		],
		'copyright'    => [
			'xmp'        => [ [ DC_NS, 'rights', 'dc' ] ],
			'exif'       => [ 'Copyright' ],
			'iptc'       => [ '2#116' ],
			'png'        => [ 'Copyright' ],
			'ooxml_core' => [ [ DC_NS, 'rights' ] ],
			'image_meta' => [ 'copyright' ],
		],
		'created'      => [
			'xmp'        => [ [ XMP_NS, 'CreateDate', 'xmp' ] ],
			'exif'       => [ 'DateTimeOriginal', 'DateTimeDigitized', 'DateTime' ],
			'iptc'       => [ '2#055', '2#060' ],
			'png'        => [ 'Creation Time' ],
			'pdf_info'   => [ 'CreationDate' ],
			'ooxml_core' => [ [ DCTERMS_NS, 'created' ] ],
			'image_meta' => [ 'created_timestamp' ],
			'date'       => true,
		],
		// OOXML cp:revision is an automatic edit counter, not an authorial version, so it is not mapped.
		'version'      => [
			'xmp' => [ [ XMP_MM_NS, 'VersionID', 'xmpMM' ] ],
		],
		'language'     => [
			'xmp'        => [ [ DC_NS, 'language', 'dc' ] ],
			'iptc'       => [ '2#135' ],
			'ooxml_core' => [ [ DC_NS, 'language' ] ],
		],
	];
}

/**
 * Return the active, validated allowlist.
 *
 * A filter that returns something other than an array fails closed: no
 * field survives, rather than silently falling back to the defaults.
 *
 * @return array<string, array>
 */
function get_fields() : array {
	/**
	 * Filter the metadata fields kept when sanitizing media.
	 *
	 * Keep in mind that anything added here is published with every matching
	 * file. Author, creator and software fields routinely carry usernames and
	 * version numbers. Changes apply to files sanitized from then on; run
	 * `wp hm-media-pii-cleaner sanitize-all --force` to re-process existing files.
	 *
	 * @param array<string, array> $fields Field ID => per-format mappings. See default_fields().
	 */
	$fields = apply_filters( FILTER, default_fields() );

	if ( ! is_array( $fields ) ) {
		report_invalid( 'The filter must return an array. No metadata fields will be kept.' );
		return [];
	}

	$validated = [];
	$prefixes  = [];
	foreach ( $fields as $id => $field ) {
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_]+$/', $id ) ) {
			report_invalid( sprintf( 'Field ID "%s" must be a lowercase string of letters, digits and underscores.', is_scalar( $id ) ? $id : gettype( $id ) ) );
			continue;
		}
		if ( ! is_array( $field ) ) {
			report_invalid( sprintf( 'Field "%s" must be an array of mappings.', $id ) );
			continue;
		}
		$validated[ $id ] = validate_field( $id, $field, $prefixes );
	}

	return $validated;
}

/**
 * Validate one field's mappings, dropping anything malformed.
 *
 * @param string $id       Field ID.
 * @param array  $field    Raw field mappings.
 * @param array  $prefixes XMP prefix => namespace bindings seen so far (updated).
 * @return array Validated field.
 */
function validate_field( string $id, array $field, array &$prefixes ) : array {
	$clean = [];

	foreach ( $field as $key => $mappings ) {
		if ( ! in_array( $key, MAPPING_KEYS, true ) ) {
			report_invalid( sprintf( 'Field "%s" has an unknown mapping key "%s".', $id, $key ) );
			continue;
		}
		if ( 'date' === $key ) {
			$clean['date'] = true === $mappings;
			continue;
		}
		if ( ! is_array( $mappings ) ) {
			report_invalid( sprintf( 'Field "%s" mapping "%s" must be an array.', $id, $key ) );
			continue;
		}

		foreach ( array_values( $mappings ) as $mapping ) {
			$valid = validate_mapping( $key, $mapping, $prefixes );
			if ( null === $valid ) {
				report_invalid( sprintf( 'Field "%s" has an invalid "%s" mapping: %s', $id, $key, wp_json_encode( $mapping ) ) );
				continue;
			}
			$clean[ $key ][] = $valid;
		}
	}

	return $clean;
}

/**
 * Validate a single mapping entry for a given format.
 *
 * @param string $key      Mapping key.
 * @param mixed  $mapping  Raw mapping entry.
 * @param array  $prefixes XMP prefix => namespace bindings seen so far (updated).
 * @return mixed Validated entry, or null when invalid.
 */
function validate_mapping( string $key, $mapping, array &$prefixes ) {
	switch ( $key ) {
		case 'xmp':
			if ( ! is_array( $mapping ) || 3 !== count( $mapping ) ) {
				return null;
			}
			[ $ns, $local, $prefix ] = array_values( $mapping );
			if ( ! is_string( $ns ) || '' === $ns || in_array( $ns, RESERVED_XMP_NAMESPACES, true )
				|| ! is_ncname( $local ) || ! is_ncname( $prefix ) || in_array( strtolower( $prefix ), RESERVED_XMP_PREFIXES, true )
			) {
				return null;
			}
			// One prefix can only be bound to one namespace in the rebuilt packet.
			if ( isset( $prefixes[ $prefix ] ) && $prefixes[ $prefix ] !== $ns ) {
				return null;
			}
			$prefixes[ $prefix ] = $ns;
			return [ $ns, $local, $prefix ];

		case 'exif':
			return is_string( $mapping ) && 1 === preg_match( '/^[A-Za-z0-9]+$/', $mapping ) ? $mapping : null;

		case 'iptc':
			return is_string( $mapping ) && 1 === preg_match( '/^\d#\d{3}$/', $mapping ) ? $mapping : null;

		case 'png':
			// PNG keywords are 1-79 printable Latin-1 characters. The XMP keyword must go through the XMP filter.
			return is_string( $mapping ) && 1 === preg_match( '/^[\x20-\x7E]{1,79}$/', $mapping ) && PNG_XMP_KEYWORD !== $mapping ? $mapping : null;

		case 'pdf_info':
			return is_string( $mapping ) && in_array( $mapping, PDF_INFO_KEYS, true ) ? $mapping : null;

		case 'ooxml_core':
			if ( ! is_array( $mapping ) || 2 !== count( $mapping ) ) {
				return null;
			}
			[ $ns, $local ] = array_values( $mapping );
			return is_string( $ns ) && isset( OOXML_CORE_NAMESPACES[ $ns ] ) && is_ncname( $local ) ? [ $ns, $local ] : null;

		case 'ooxml_app':
			return is_ncname( $mapping ) ? $mapping : null;

		case 'image_meta':
			return is_string( $mapping ) && 1 === preg_match( '/^[a-z0-9_]+$/', $mapping ) ? $mapping : null;
	}

	return null;
}

/**
 * Is this a valid XML NCName (a name without a colon)? Restricted to ASCII,
 * which covers every standard metadata property name.
 *
 * @param mixed $value Candidate name.
 * @return bool
 */
function is_ncname( $value ) : bool {
	return is_string( $value ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9._-]*$/', $value );
}

/**
 * Report a developer error in the filter without breaking sanitization.
 *
 * @param string $message Problem description.
 */
function report_invalid( string $message ) : void {
	if ( function_exists( '_doing_it_wrong' ) ) {
		_doing_it_wrong( esc_html( FILTER ), esc_html( $message ), '1.0.0' );
	}
}

/**
 * Collect every entry for one mapping key across all fields.
 *
 * @param string     $key    Mapping key.
 * @param array|null $fields Validated fields; resolved when null.
 * @return array
 */
function collect( string $key, ?array $fields = null ) : array {
	$fields ??= get_fields();
	$entries  = [];
	foreach ( $fields as $field ) {
		foreach ( $field[ $key ] ?? [] as $entry ) {
			$entries[] = $entry;
		}
	}
	return array_values( array_unique( $entries, SORT_REGULAR ) );
}

/**
 * XMP properties kept: list of [ namespace, local name, prefix ].
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return array
 */
function xmp_properties( ?array $fields = null ) : array {
	return collect( 'xmp', $fields );
}

/**
 * PNG text keywords kept.
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return string[]
 */
function png_keywords( ?array $fields = null ) : array {
	return collect( 'png', $fields );
}

/**
 * PDF /Info keys passed through.
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return string[]
 */
function pdf_info_keys( ?array $fields = null ) : array {
	return collect( 'pdf_info', $fields );
}

/**
 * OOXML docProps/core.xml elements kept: list of [ namespace, local name ].
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return array
 */
function ooxml_core_properties( ?array $fields = null ) : array {
	return collect( 'ooxml_core', $fields );
}

/**
 * OOXML docProps/app.xml element names kept.
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return string[]
 */
function ooxml_app_properties( ?array $fields = null ) : array {
	return collect( 'ooxml_app', $fields );
}

/**
 * WordPress image_meta keys kept.
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return string[]
 */
function image_meta_keys( ?array $fields = null ) : array {
	return collect( 'image_meta', $fields );
}

/**
 * Fields that convert binary EXIF/IPTC values into XMP: field ID => [
 *   'exif' => tag names, 'iptc' => dataset codes, 'xmp' => target property, 'date' => bool ].
 * Only fields with at least one XMP property can receive converted values.
 *
 * @param array|null $fields Validated fields; resolved when null.
 * @return array<string, array>
 */
function conversion_targets( ?array $fields = null ) : array {
	$fields ??= get_fields();
	$targets  = [];
	foreach ( $fields as $id => $field ) {
		if ( empty( $field['xmp'] ) ) {
			continue;
		}
		$targets[ $id ] = [
			'exif' => $field['exif'] ?? [],
			'iptc' => $field['iptc'] ?? [],
			'xmp'  => $field['xmp'][0],
			'date' => ! empty( $field['date'] ),
		];
	}
	return $targets;
}
