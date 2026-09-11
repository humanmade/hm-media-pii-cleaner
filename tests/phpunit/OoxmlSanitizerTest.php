<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Ooxml;

/**
 * Tests for Ooxml\strip_ooxml_metadata().
 */
class OoxmlSanitizerTest extends TestCase {

	private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
		. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
		. '</Types>';

	private function core_xml( string $extra = '' ) : string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
			. ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:title>Public Title</dc:title>'
			. '<dc:creator>jdoe</dc:creator>'
			. '<cp:lastModifiedBy>Jane Doe</cp:lastModifiedBy>'
			. '<cp:revision>7</cp:revision>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">2026-06-01T12:00:00Z</dcterms:created>'
			. '<dcterms:modified xsi:type="dcterms:W3CDTF">2026-07-01T09:00:00Z</dcterms:modified>'
			. $extra
			. '</cp:coreProperties>';
	}

	private function app_xml() : string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
			. ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
			. '<Application>Microsoft PowerPoint</Application>'
			. '<Company>Example Publisher</Company>'
			. '</Properties>';
	}

	private function build_pptx( array $extra_entries = [] ) : string {
		$tmp = tempnam( sys_get_temp_dir(), 'hmpc-ooxml-' );
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', self::CONTENT_TYPES );
		$zip->addFromString( 'docProps/core.xml', $this->core_xml() );
		$zip->addFromString( 'docProps/app.xml', $this->app_xml() );
		$zip->addFromString( 'ppt/presentation.xml', '<p:presentation xmlns:p="x"/>' );
		foreach ( $extra_entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();

		$bytes = file_get_contents( $tmp );
		unlink( $tmp );

		return $bytes;
	}

	private function entry( string $data, string $name ) : string {
		$tmp = tempnam( sys_get_temp_dir(), 'hmpc-ooxml-read-' );
		file_put_contents( $tmp, $data );
		$zip = new \ZipArchive();
		$zip->open( $tmp );
		$value = $zip->getFromName( $name );
		$zip->close();
		unlink( $tmp );

		return false === $value ? '' : $value;
	}

	public function test_filters_core_properties_to_the_allowlist() : void {
		$result = Ooxml\strip_ooxml_metadata( $this->build_pptx() );
		$core   = $this->entry( $result['data'], 'docProps/core.xml' );

		$this->assertStringContainsString( 'Public Title', $core );
		$this->assertStringContainsString( '2026-06-01T12:00:00Z', $core );
		$this->assertStringNotContainsString( 'jdoe', $core );
		$this->assertStringNotContainsString( 'Jane Doe', $core );
		$this->assertStringNotContainsString( '<cp:revision', $core );
		$this->assertStringNotContainsString( '2026-07-01T09:00:00Z', $core ); // dcterms:modified.
	}

	public function test_filters_app_properties_to_company_only() : void {
		$result = Ooxml\strip_ooxml_metadata( $this->build_pptx() );
		$app    = $this->entry( $result['data'], 'docProps/app.xml' );

		$this->assertStringContainsString( 'Example Publisher', $app );
		$this->assertStringNotContainsString( 'Microsoft PowerPoint', $app );
	}

	public function test_empties_custom_properties() : void {
		$custom = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"'
			. ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
			. '<property pid="2" fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" name="TrackingID">'
			. '<vt:lpwstr>internal-secret-id</vt:lpwstr></property></Properties>';

		$result = Ooxml\strip_ooxml_metadata( $this->build_pptx( [ 'docProps/custom.xml' => $custom ] ) );
		$got    = $this->entry( $result['data'], 'docProps/custom.xml' );

		$this->assertStringNotContainsString( 'internal-secret-id', $got );
		$this->assertStringNotContainsString( 'TrackingID', $got );
	}

	public function test_leaves_document_content_untouched() : void {
		$result = Ooxml\strip_ooxml_metadata( $this->build_pptx() );
		$this->assertSame( '<p:presentation xmlns:p="x"/>', $this->entry( $result['data'], 'ppt/presentation.xml' ) );
	}

	public function test_drops_nested_disallowed_content_inside_an_allowed_element() : void {
		$core = $this->core_xml( '' );
		$core = str_replace(
			'<dc:title>Public Title</dc:title>',
			'<dc:title><evil:secret xmlns:evil="urn:evil">Leaked</evil:secret>Public Title</dc:title>',
			$core
		);
		$pptx   = $this->build_pptx();
		// Overwrite core.xml with the smuggling attempt via a fresh package.
		$tmp = tempnam( sys_get_temp_dir(), 'hmpc-ooxml-' );
		file_put_contents( $tmp, $pptx );
		$zip = new \ZipArchive();
		$zip->open( $tmp );
		$zip->addFromString( 'docProps/core.xml', $core );
		$zip->close();
		$data = file_get_contents( $tmp );
		unlink( $tmp );

		$result = Ooxml\strip_ooxml_metadata( $data );
		$got    = $this->entry( $result['data'], 'docProps/core.xml' );

		$this->assertStringNotContainsString( 'Leaked', $got );
		$this->assertStringNotContainsString( 'Public Title', $got ); // Whole value dropped, not just the smuggled part.
	}

	public function test_idempotent_on_already_clean_output() : void {
		$first  = Ooxml\strip_ooxml_metadata( $this->build_pptx() );
		$second = Ooxml\strip_ooxml_metadata( $first['data'] );

		$this->assertSame( 0, $second['stripped_parts'] );
	}

	public function test_rejects_non_zip_input() : void {
		$this->expectException( \RuntimeException::class );
		Ooxml\strip_ooxml_metadata( 'not a zip file' );
	}

	public function test_rejects_oversized_metadata_property_before_decompression() : void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'metadata property entry exceeds' );

		Ooxml\strip_ooxml_metadata(
			$this->build_pptx(
				[ 'docProps/core.xml' => str_repeat( 'A', HM_MEDIA_PII_CLEANER_MAX_OOXML_PROPERTY_BYTES + 1 ) ]
			)
		);
	}

	public function test_rejects_zip_bomb_compression_ratio() : void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'compression ratio' );

		Ooxml\strip_ooxml_metadata(
			$this->build_pptx( [ 'ppt/media/highly-compressible.bin' => str_repeat( 'A', 1024 * 1024 ) ] )
		);
	}

	public function test_rejects_path_traversal_entry_names() : void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'unsafe entry name' );

		Ooxml\strip_ooxml_metadata( $this->build_pptx( [ '../outside.xml' => '<secret/>' ] ) );
	}

	public function test_rejects_case_ambiguous_property_entries() : void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'duplicate or ambiguous entry name' );

		Ooxml\strip_ooxml_metadata( $this->build_pptx( [ 'DOCPROPS/CORE.XML' => '<secret/>' ] ) );
	}
}
