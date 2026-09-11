<?php
/**
 * SVG metadata hardening.
 *
 * `safe-svg` (darylldoyle/safe-svg, using enshrined/svg-sanitize) already runs
 * at upload time and strips script/XSS vectors — but its allowed-tags list
 * deliberately keeps <metadata>/<title>/<desc> (they're harmless from an XSS
 * standpoint). This is a second, metadata-focused pass on top of that,
 * removing authoring/editor cruft the security pass leaves behind.
 *
 * <title>/<desc> are deliberately NOT stripped: they're part of the SVG
 * accessibility model (accessible name/description, and the native hover
 * tooltip for inline SVG), not hidden metadata. The actual leak vector for
 * authors/usernames/software versions is <metadata> — design tools embed an
 * RDF blob there (dc:creator, cc:license, etc.). Rather than removing that
 * blob unconditionally, its RDF content is filtered down to the
 * allowlisted properties (title/rights/language/publisher/version — see XmpFilter, the same
 * Dublin Core vocabulary design tools already use here), and only the
 * disallowed rest is dropped.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Images;

use HM\MediaPiiCleaner\XmpFilter;

/**
 * Element names used by design tools to embed non-rendering, non-accessible
 * authoring metadata (RDF creator/rights/software info) rather than content.
 */
const SVG_METADATA_ELEMENTS = [ 'metadata' ];
const SVG_METADATA_ATTRIBUTES = [
	'about',
	'content',
	'datatype',
	'prefix',
	'property',
	'rel',
	'resource',
	'rev',
	'typeof',
	'vocab',
	'version',
	'author',
	'creator',
	'software',
];
const SVG_XML_NS               = 'http://www.w3.org/XML/1998/namespace';
const SVG_XLINK_NS             = 'http://www.w3.org/1999/xlink';

/**
 * Filter metadata, remove comments, and strip non-rendering namespaced
 * attributes from SVG markup, while preserving viewBox/width/height so
 * visual rendering is unaffected.
 *
 * @param string $svg Raw SVG markup.
 * @return string Hardened SVG markup.
 * @throws \RuntimeException If the SVG can't be parsed or re-serialized.
 */
function strip_svg_metadata( string $svg ) : string {
	// DOMDocument::loadXML() throws a \ValueError (not a plain parse-failure
	// "return false") for an empty string — e.g. a 0-byte SVG file — which
	// would otherwise bypass the try/catch below entirely. Fail the same
	// safe way as any other unparseable SVG.
	if ( '' === trim( $svg ) ) {
		throw new \RuntimeException( 'Empty SVG file.' );
	}

	$previous_use_errors = libxml_use_internal_errors( true );

	try {
		$dom = new \DOMDocument();
		$dom->preserveWhiteSpace  = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		$dom->resolveExternals    = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		$dom->substituteEntities  = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.

		$loaded = $dom->loadXML( $svg, LIBXML_NONET );

		if ( ! $loaded || ! $dom->documentElement ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
			throw new \RuntimeException( 'Unable to parse SVG as XML: ' . describe_libxml_errors() );
		}
		if ( null !== $dom->doctype ) {
			throw new \RuntimeException( 'SVG files containing a DTD are not allowed.' );
		}

		$root = $dom->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.

		foreach ( SVG_METADATA_ELEMENTS as $local_name ) {
			$nodes = iterator_to_array( $dom->getElementsByTagNameNS( '*', $local_name ) );
			foreach ( $nodes as $node ) {
				filter_or_remove_metadata_element( $dom, $node );
			}
		}

		$xpath = new \DOMXPath( $dom );
		foreach ( iterator_to_array( $xpath->query( '//comment()' ) ) as $comment ) {
			$comment->parentNode->removeChild( $comment ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		// Non-SVG namespaces outside <metadata> are editor/custom metadata by
		// default. Preserve only XML language/spacing and XLink rendering links;
		// the <metadata> subtree is skipped because it was already rebuilt by the
		// stricter XMP allowlist above.
		// Note: this deliberately does not attempt to remove the corresponding
		// namespace *declarations* themselves —
		// DOMElement::removeAttribute()/removeAttributeNS() cannot reliably
		// remove those in libxml (they're stored outside the normal attribute
		// list), and an unused namespace declaration with no attributes left
		// using it is inert, not an identifying metadata leak.
		foreach ( iterator_to_array( $xpath->query( '//*[not(ancestor-or-self::*[local-name()="metadata"])]' ) ) as $element ) {
			if ( ! $element->hasAttributes() ) {
				continue;
			}
			$to_remove = [];
			foreach ( $element->attributes as $attr ) {
				if ( $attr->namespaceURI && ! in_array( $attr->namespaceURI, [ SVG_XML_NS, SVG_XLINK_NS ], true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMAttr property.
					$to_remove[] = [ $attr->namespaceURI, $attr->localName ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMAttr properties.
				} elseif ( ! $attr->namespaceURI ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMAttr property.
					$local_name = strtolower( $attr->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMAttr property.
					if ( str_starts_with( $local_name, 'data-' ) || in_array( $local_name, SVG_METADATA_ATTRIBUTES, true ) ) {
						$to_remove[] = [ null, $attr->name ];
					}
				}
			}
			foreach ( $to_remove as [ $namespace_uri, $local_name ] ) {
				if ( null === $namespace_uri ) {
					$element->removeAttribute( $local_name );
				} else {
					$element->removeAttributeNS( $namespace_uri, $local_name );
				}
			}
		}

		$result = $dom->saveXML();

		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to re-serialize sanitized SVG.' );
		}

		return $result;
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_use_errors );
	}
}

/**
 * Filter an SVG <metadata> element's RDF content down to the
 * allowlisted properties, or remove the element
 * entirely if nothing allowlisted survives — same policy as the PDF/JPEG/
 * WebP XMP paths, applied to the identical Dublin Core vocabulary design
 * tools already use inside SVG <metadata>.
 *
 * @param \DOMDocument $dom           The SVG document (element is imported into this).
 * @param \DOMElement  $metadata_node The <metadata> element to filter in place.
 */
function filter_or_remove_metadata_element( \DOMDocument $dom, \DOMElement $metadata_node ) : void {
	// Serialize the *whole* document, not just this node: the rdf:/dc:
	// namespace declarations the metadata element's descendants rely on
	// usually live on the <svg> root, and DOMDocument::saveXML() does not
	// hoist ancestor xmlns declarations onto a subtree serialized alone.
	// filter_xmp_packet() only ever looks at specific namespaced elements
	// wherever they appear, so feeding it the full document is safe.
	$serialized = $dom->saveXML();

	if ( false === $serialized ) {
		$metadata_node->parentNode->removeChild( $metadata_node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		return;
	}

	try {
		$filtered_packet = XmpFilter\filter_xmp_packet( $serialized );
	} catch ( \RuntimeException $e ) {
		$metadata_node->parentNode->removeChild( $metadata_node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		return;
	}

	if ( XmpFilter\EMPTY_PACKET === $filtered_packet ) {
		$metadata_node->parentNode->removeChild( $metadata_node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		return;
	}

	$filtered_dom = new \DOMDocument();
	$filtered_dom->resolveExternals   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
	$filtered_dom->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
	$filtered_dom->loadXML( $filtered_packet, LIBXML_NONET );
	$rdf = $filtered_dom->getElementsByTagNameNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'RDF' )->item( 0 );

	while ( $metadata_node->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
		$metadata_node->removeChild( $metadata_node->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
	}

	if ( null !== $rdf ) {
		$metadata_node->appendChild( $dom->importNode( $rdf, true ) );
	}
}

/**
 * Read the root <svg> element's viewBox/width/height for a before/after
 * visual-fidelity comparison.
 *
 * @param string $svg Raw SVG markup.
 * @return array{viewBox: ?string, width: ?string, height: ?string}
 * @throws \RuntimeException If the SVG can't be parsed.
 */
function get_svg_dimensions( string $svg ) : array {
	// See the matching guard in strip_svg_metadata() — DOMDocument::loadXML()
	// throws a \ValueError, not a catchable parse failure, for an empty string.
	if ( '' === trim( $svg ) ) {
		throw new \RuntimeException( 'Empty SVG file.' );
	}

	$previous_use_errors = libxml_use_internal_errors( true );

	try {
		$dom = new \DOMDocument();
		$dom->resolveExternals   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		$dom->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		if ( ! $dom->loadXML( $svg, LIBXML_NONET ) || ! $dom->documentElement ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
			throw new \RuntimeException( 'Unable to parse SVG as XML: ' . describe_libxml_errors() );
		}
		if ( null !== $dom->doctype ) {
			throw new \RuntimeException( 'SVG files containing a DTD are not allowed.' );
		}

		$root = $dom->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.

		return [
			'viewBox' => $root->getAttribute( 'viewBox' ) ?: null,
			'width'   => $root->getAttribute( 'width' ) ?: null,
			'height'  => $root->getAttribute( 'height' ) ?: null,
		];
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_use_errors );
	}
}

/**
 * Format accumulated libxml errors for an exception message.
 *
 * @return string
 */
function describe_libxml_errors() : string {
	$messages = array_map(
		static fn( \LibXMLError $error ) => trim( $error->message ),
		libxml_get_errors()
	);

	return implode( '; ', $messages );
}
