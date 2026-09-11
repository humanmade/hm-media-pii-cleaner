<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\XmpFilter;

/**
 * Tests for the shared fail-closed XMP/RDF allowlist.
 */
class XmpFilterTest extends TestCase {

	private function packet( string $properties, string $extra_namespaces = '' ) : string {
		return '<?xpacket begin="" id="w"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:xmp="http://ns.adobe.com/xap/1.0/" ' . $extra_namespaces . '>'
			. '<rdf:Description rdf:about="">' . $properties . '</rdf:Description>'
			. '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
	}

	public function test_keeps_scalar_and_compact_allowlisted_properties() : void {
		$xmp = str_replace(
			'<rdf:Description rdf:about="">',
			'<rdf:Description rdf:about="" dc:language="en-GB">',
			$this->packet( '<dc:title>Public title</dc:title><xmp:CreateDate>2026-07-22T10:00:00+03:00</xmp:CreateDate>' )
		);

		$filtered = XmpFilter\filter_xmp_packet( $xmp );

		$this->assertStringContainsString( '<dc:title', $filtered );
		$this->assertStringContainsString( '>Public title</dc:title>', $filtered );
		$this->assertStringContainsString( 'dc:language="en-GB"', $filtered );
		$this->assertStringContainsString( '<xmp:CreateDate', $filtered );
		$this->assertStringContainsString( '>2026-07-22T10:00:00+03:00</xmp:CreateDate>', $filtered );
	}

	public function test_rebuilds_multilingual_rdf_container() : void {
		$xmp = $this->packet(
			'<dc:title><rdf:Alt>'
			. '<rdf:li xml:lang="x-default">Default title</rdf:li>'
			. '<rdf:li xml:lang="bg">Заглавие</rdf:li>'
			. '</rdf:Alt></dc:title>'
		);

		$filtered = XmpFilter\filter_xmp_packet( $xmp );

		$this->assertStringContainsString( '<rdf:Alt>', $filtered );
		$this->assertStringContainsString( 'xml:lang="x-default"', $filtered );
		$this->assertStringContainsString( 'xml:lang="bg"', $filtered );
		$this->assertStringContainsString( 'Заглавие', $filtered );
	}

	public function test_preserves_standard_organisation_and_document_version_fields() : void {
		$xmp = $this->packet(
			'<dc:publisher><rdf:Bag><rdf:li>Example Publisher</rdf:li></rdf:Bag></dc:publisher>'
			. '<xmpMM:VersionID>7</xmpMM:VersionID>',
			'xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/"'
		);

		$filtered = XmpFilter\filter_xmp_packet( $xmp );

		$this->assertStringContainsString( 'Example Publisher', $filtered );
		$this->assertStringContainsString( 'xmpMM:VersionID', $filtered );
		$this->assertStringContainsString( '>7</xmpMM:VersionID>', $filtered );
	}

	public function test_drops_allowlisted_property_with_disallowed_child() : void {
		$xmp = $this->packet(
			'<dc:title><pdf:Producer>Secret Tool</pdf:Producer></dc:title>',
			'xmlns:pdf="http://ns.adobe.com/pdf/1.3/"'
		);

		$filtered = XmpFilter\filter_xmp_packet( $xmp );

		$this->assertSame( XmpFilter\EMPTY_PACKET, $filtered );
		$this->assertStringNotContainsString( 'Secret Tool', $filtered );
	}

	public function test_drops_allowlisted_property_with_disallowed_attribute() : void {
		$xmp = $this->packet(
			'<dc:title pdf:Producer="Secret Tool">Public title</dc:title>',
			'xmlns:pdf="http://ns.adobe.com/pdf/1.3/"'
		);

		$this->assertSame( XmpFilter\EMPTY_PACKET, XmpFilter\filter_xmp_packet( $xmp ) );
	}

	public function test_does_not_discover_allowlisted_property_inside_disallowed_wrapper() : void {
		$xmp = $this->packet(
			'<pdf:Producer><dc:title>Hidden title</dc:title></pdf:Producer>',
			'xmlns:pdf="http://ns.adobe.com/pdf/1.3/"'
		);

		$this->assertSame( XmpFilter\EMPTY_PACKET, XmpFilter\filter_xmp_packet( $xmp ) );
	}

	public function test_rejects_external_entity_without_reading_local_file() : void {
		$path   = sys_get_temp_dir() . '/hm-xmp-entity-' . getmypid() . '.txt';
		$secret = 'LOCAL_FILE_SECRET_SHOULD_NOT_SURVIVE';
		file_put_contents( $path, $secret );

		$xmp = '<!DOCTYPE x:xmpmeta [<!ENTITY local SYSTEM "file://' . $path . '">]>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<rdf:Description><dc:title>&local;</dc:title></rdf:Description>'
			. '</rdf:RDF></x:xmpmeta>';

		try {
			XmpFilter\filter_xmp_packet( $xmp );
			$this->fail( 'DTD-bearing XMP should be rejected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'DTD', $e->getMessage() );
			$this->assertStringNotContainsString( $secret, $e->getMessage() );
		} finally {
			unlink( $path );
		}
	}

	public function test_filtered_output_is_idempotent() : void {
		$filtered = XmpFilter\filter_xmp_packet( $this->packet( '<dc:rights>Copyright 2026</dc:rights>' ) );

		$this->assertSame( $filtered, XmpFilter\filter_xmp_packet( $filtered ) );
	}

	public function test_merges_multiple_packets_only_after_filtering_each_one() : void {
		$merged = XmpFilter\merge_xmp_packets( [
			$this->packet( '<dc:title>Public title</dc:title>' ),
			$this->packet(
				'<dc:rights>Copyright 2026</dc:rights><pdf:Producer>Secret Tool</pdf:Producer>',
				'xmlns:pdf="http://ns.adobe.com/pdf/1.3/"'
			),
		] );

		$this->assertStringContainsString( 'Public title', $merged );
		$this->assertStringContainsString( 'Copyright 2026', $merged );
		$this->assertStringNotContainsString( 'Secret Tool', $merged );
		$this->assertStringNotContainsString( 'pdf:Producer', $merged );
	}

	public function test_fixed_length_filter_preserves_exact_budget() : void {
		$length   = 1024;
		$filtered = XmpFilter\filter_xmp_packet_to_length( $this->packet( '<dc:title>Public title</dc:title>' ), $length );

		$this->assertSame( $length, strlen( $filtered ) );
		$this->assertStringContainsString( 'Public title', $filtered );
	}
}
