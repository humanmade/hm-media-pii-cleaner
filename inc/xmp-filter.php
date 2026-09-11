<?php
/**
 * Shared XMP/RDF packet filter: given a raw XMP packet (the same plain-XML
 * format embedded in PDFs, JPEGs, WebPs, and SVGs), keep only the specific
 * properties the allowlist permits to survive sanitization —
 * title, organisation, copyright notice, publication/created date, document
 * version, and language — and drop every other property (creator, software,
 * tool history, GPS, etc).
 *
 * Deliberately narrow: this only understands the allowlisted properties
 * (see Fields\default_fields() and the hm_media_pii_cleaner_allowed_fields
 * filter), expressed as an RDF attribute or as a child element (both are
 * valid XMP). Anything else in the packet, known or
 * unknown, is dropped — fail-closed by construction, not by an exclusion list.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\XmpFilter;

use HM\MediaPiiCleaner\Fields;

const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
const X_NS   = 'adobe:ns:meta/';
const XML_NS = 'http://www.w3.org/XML/1998/namespace';

/**
 * Minimal, harmless empty-XMP-packet placeholder — used when nothing
 * allowlisted was found, or when the filtered result doesn't fit the
 * available space.
 */
const EMPTY_PACKET = '<?xpacket begin="" id="w"?><x:xmpmeta xmlns:x="adobe:ns:meta/">'
	. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"></rdf:RDF>'
	. '</x:xmpmeta><?xpacket end="w"?>';

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API properties.
/**
 * Filter a raw XMP packet down to only the allowlisted properties.
 *
 * @param string $xmp Raw XMP packet (including the <?xpacket ...?> wrapper, if present).
 * @return string Filtered XMP packet. Never larger than a trivial fixed overhead
 *                over the kept values themselves — callers needing a fixed byte
 *                budget (e.g. an in-place stream patch) must check the length
 *                themselves and fall back to EMPTY_PACKET if it doesn't fit.
 * @throws \RuntimeException If the packet can't be parsed as XML.
 */
function filter_xmp_packet( string $xmp ) : string {
	// DOMDocument::loadXML() throws a \ValueError (not caught below — it
	// doesn't extend \RuntimeException) for an empty string, instead of the
	// usual "return false" parse-failure signal every other malformed input
	// gets. Guard explicitly so an empty/whitespace-only packet fails the
	// same safe way as any other unparseable one.
	if ( '' === trim( $xmp ) ) {
		throw new \RuntimeException( 'Empty XMP packet.' );
	}

	$previous_use_errors = libxml_use_internal_errors( true );

	try {
		$dom = new \DOMDocument();
		$dom->resolveExternals   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		$dom->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
		if ( ! $dom->loadXML( $xmp, LIBXML_NONET ) || ! $dom->documentElement ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMDocument property.
			throw new \RuntimeException( 'Unable to parse XMP packet as XML.' );
		}
		if ( null !== $dom->doctype ) {
			throw new \RuntimeException( 'XMP packets containing a DTD are not allowed.' );
		}

		$allowed_properties = Fields\xmp_properties();

		$kept_elements = [];
		foreach ( iterator_to_array( $dom->getElementsByTagNameNS( RDF_NS, 'Description' ) ) as $description ) {
			foreach ( iterator_to_array( $description->childNodes ) as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}

				$property = sanitize_property_element( $node, $allowed_properties );
				if ( null !== $property ) {
					$kept_elements[] = $property;
				}
			}
		}

		// The same properties can also appear as a plain attribute on
		// rdf:Description (the "compact" RDF form) rather than a child element.
		$kept_attrs = [];
		foreach ( iterator_to_array( $dom->getElementsByTagNameNS( RDF_NS, 'Description' ) ) as $description ) {
			if ( ! $description->hasAttributes() ) {
				continue;
			}
			foreach ( $description->attributes as $attr ) {
				foreach ( $allowed_properties as [ $ns, $local_name, $prefix ] ) {
					if ( $attr->namespaceURI === $ns && $attr->localName === $local_name ) {
						$kept_attrs[ $prefix . ':' . $local_name ] = [ $ns, $local_name, $attr->value ];
					}
				}
			}
		}

		if ( empty( $kept_elements ) && empty( $kept_attrs ) ) {
			return EMPTY_PACKET;
		}

		return build_filtered_packet( $kept_elements, $kept_attrs );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_use_errors );
	}
}

/**
 * Convert an allowlisted property into a data-only representation that can be
 * rebuilt without importing any source nodes. Unexpected structure causes the
 * whole property to be dropped rather than flattened into apparently-safe text.
 *
 * @param \DOMElement $node               Candidate property element.
 * @param array       $allowed_properties Allowed [namespace, local name, prefix] list.
 * @return array|null Sanitized property data, or null when disallowed/unsafe.
 */
function sanitize_property_element( \DOMElement $node, array $allowed_properties ) : ?array {
	$definition = null;
	foreach ( $allowed_properties as [ $ns, $local_name, $prefix ] ) {
		if ( $node->namespaceURI === $ns && $node->localName === $local_name ) {
			$definition = [ $ns, $local_name, $prefix ];
			break;
		}
	}

	if ( null === $definition ) {
		return null;
	}

	$lang = null;
	foreach ( iterator_to_array( $node->attributes ) as $attr ) {
		if ( XML_NS === $attr->namespaceURI && 'lang' === $attr->localName ) {
			$lang = $attr->value;
			continue;
		}
		return null;
	}

	$element_children = [];
	$text              = '';
	foreach ( iterator_to_array( $node->childNodes ) as $child ) {
		if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
			$text .= $child->nodeValue;
		} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
			$element_children[] = $child;
		} else {
			return null;
		}
	}

	[ $ns, $local_name, $prefix ] = $definition;
	if ( empty( $element_children ) ) {
		return [
			'namespace'  => $ns,
			'local_name' => $local_name,
			'prefix'     => $prefix,
			'value'      => $text,
			'lang'       => $lang,
		];
	}

	if ( 1 !== count( $element_children ) || '' !== trim( $text ) || null !== $lang ) {
		return null;
	}

	$container = sanitize_rdf_container( $element_children[0] );
	if ( null === $container ) {
		return null;
	}

	return [
		'namespace'  => $ns,
		'local_name' => $local_name,
		'prefix'     => $prefix,
		'container'  => $container,
	];
}

/**
 * Validate and extract a standard RDF Alt/Bag/Seq container. Only text-only
 * rdf:li values and their optional xml:lang attribute are retained.
 *
 * @param \DOMElement $container Candidate RDF container.
 * @return array|null Sanitized container data, or null when unsafe.
 */
function sanitize_rdf_container( \DOMElement $container ) : ?array {
	if ( RDF_NS !== $container->namespaceURI || ! in_array( $container->localName, [ 'Alt', 'Bag', 'Seq' ], true ) || $container->hasAttributes() ) {
		return null;
	}

	$items = [];
	foreach ( iterator_to_array( $container->childNodes ) as $child ) {
		if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->nodeValue ) ) {
			continue;
		}
		if ( ! $child instanceof \DOMElement || RDF_NS !== $child->namespaceURI || 'li' !== $child->localName ) {
			return null;
		}

		$lang = null;
		foreach ( iterator_to_array( $child->attributes ) as $attr ) {
			if ( XML_NS === $attr->namespaceURI && 'lang' === $attr->localName ) {
				$lang = $attr->value;
				continue;
			}
			return null;
		}

		$value = '';
		foreach ( iterator_to_array( $child->childNodes ) as $value_node ) {
			if ( XML_TEXT_NODE !== $value_node->nodeType && XML_CDATA_SECTION_NODE !== $value_node->nodeType ) {
				return null;
			}
			$value .= $value_node->nodeValue;
		}

		$items[] = [
			'value' => $value,
			'lang'  => $lang,
		];
	}

	return [
		'type'  => $container->localName,
		'items' => $items,
	];
}

/**
 * Merge multiple raw XMP packets after independently filtering each one.
 * Invalid packets are ignored; no source node reaches the merged output
 * without first passing filter_xmp_packet().
 *
 * @param string[] $packets Raw XMP packets.
 * @return string One filtered packet, or EMPTY_PACKET when none survive.
 */
function merge_xmp_packets( array $packets ) : string {
	$out     = new \DOMDocument( '1.0', 'UTF-8' );
	$xmpmeta = $out->createElementNS( X_NS, 'x:xmpmeta' );
	$rdf     = $out->createElementNS( RDF_NS, 'rdf:RDF' );
	$out->appendChild( $xmpmeta );
	$xmpmeta->appendChild( $rdf );

	$found = false;
	foreach ( $packets as $packet ) {
		try {
			$filtered = filter_xmp_packet( $packet );
		} catch ( \RuntimeException $e ) {
			continue;
		}
		if ( EMPTY_PACKET === $filtered ) {
			continue;
		}

		$packet_dom = new \DOMDocument();
		if ( ! $packet_dom->loadXML( $filtered, LIBXML_NONET ) ) {
			continue;
		}
		foreach ( iterator_to_array( $packet_dom->getElementsByTagNameNS( RDF_NS, 'Description' ) ) as $description ) {
			$rdf->appendChild( $out->importNode( $description, true ) );
			$found = true;
		}
	}

	if ( ! $found ) {
		return EMPTY_PACKET;
	}

	$merged = $out->saveXML( $xmpmeta );
	return false === $merged ? EMPTY_PACKET : filter_xmp_packet( $merged );
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Build a fresh, minimal XMP packet containing only the given already-vetted
 * elements/attributes.
 *
 * @param array         $kept_elements Sanitized allowlisted property data.
 * @param array         $kept_attrs    Map of "prefix:localName" => [namespace, localName, value].
 * @return string
 */
function build_filtered_packet( array $kept_elements, array $kept_attrs ) : string {
	$out = new \DOMDocument( '1.0', 'UTF-8' );

	$xmpmeta = $out->createElementNS( X_NS, 'x:xmpmeta' );
	$out->appendChild( $xmpmeta );

	$rdf = $out->createElementNS( RDF_NS, 'rdf:RDF' );
	$xmpmeta->appendChild( $rdf );

	$description = $out->createElementNS( RDF_NS, 'rdf:Description' );
	$description->setAttributeNS( RDF_NS, 'rdf:about', '' );
	$rdf->appendChild( $description );

	foreach ( $kept_elements as $property ) {
		$node = $out->createElementNS( $property['namespace'], $property['prefix'] . ':' . $property['local_name'] );
		$description->appendChild( $node );

		if ( isset( $property['container'] ) ) {
			$container = $out->createElementNS( RDF_NS, 'rdf:' . $property['container']['type'] );
			$node->appendChild( $container );
			foreach ( $property['container']['items'] as $item ) {
				$li = $out->createElementNS( RDF_NS, 'rdf:li' );
				if ( null !== $item['lang'] ) {
					$li->setAttributeNS( XML_NS, 'xml:lang', $item['lang'] );
				}
				$li->appendChild( $out->createTextNode( $item['value'] ) );
				$container->appendChild( $li );
			}
		} else {
			if ( null !== $property['lang'] ) {
				$node->setAttributeNS( XML_NS, 'xml:lang', $property['lang'] );
			}
			$node->appendChild( $out->createTextNode( $property['value'] ) );
		}
	}

	foreach ( $kept_attrs as $qualified_name => [ $ns, $local_name, $value ] ) {
		$description->setAttributeNS( $ns, $qualified_name, $value );
	}

	$body = $out->saveXML( $xmpmeta );

	return '<?xpacket begin="" id="w"?>' . $body . '<?xpacket end="w"?>';
}

/**
 * Filter a packet to fit an exact byte budget (e.g. an in-place stream
 * patch that must not change the surrounding file's byte offsets). Falls
 * back to the empty packet, right-padded, if the filtered content — or the
 * packet itself, if unparseable — doesn't fit.
 *
 * @param string $xmp            Raw XMP packet.
 * @param int    $target_length  Exact byte length the result must occupy.
 * @return string Exactly $target_length bytes.
 */
function filter_xmp_packet_to_length( string $xmp, int $target_length ) : string {
	try {
		$filtered = filter_xmp_packet( $xmp );
	} catch ( \RuntimeException $e ) {
		$filtered = EMPTY_PACKET;
	}

	if ( strlen( $filtered ) > $target_length ) {
		$filtered = EMPTY_PACKET;
	}

	if ( strlen( $filtered ) > $target_length ) {
		// Even the empty packet doesn't fit (pathologically small stream) —
		// pad/truncate defensively rather than return an over-length string.
		return substr( str_pad( $filtered, $target_length, ' ' ), 0, $target_length );
	}

	return $filtered . str_repeat( ' ', $target_length - strlen( $filtered ) );
}
