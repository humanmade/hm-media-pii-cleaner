<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Images;

/**
 * Tests for Images\strip_png_metadata().
 */
class PngSanitizerTest extends TestCase {

	/**
	 * Build a minimal, structurally-valid PNG chunk stream. CRCs are not
	 * validated by the parser under test, so placeholder CRC bytes are fine.
	 */
	private function chunk( string $type, string $data ) : string {
		return pack( 'N', strlen( $data ) ) . $type . $data . "\x00\x00\x00\x00";
	}

	private function build_png( array $extra_chunks ) : string {
		$ihdr = $this->chunk( 'IHDR', str_repeat( "\x00", 13 ) );
		$idat = $this->chunk( 'IDAT', 'not-real-deflate-data' );
		$iend = $this->chunk( 'IEND', '' );

		return Images\PNG_SIGNATURE . $ihdr . implode( '', $extra_chunks ) . $idat . $iend;
	}

	private function xmp_packet() : string {
		return '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF'
			. ' xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/"'
			. ' xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
			. '<rdf:Description><dc:language>en-GB</dc:language>'
			. '<dc:publisher>Example Publisher</dc:publisher>'
			. '<xmpMM:VersionID>7</xmpMM:VersionID>'
			. '<pdf:Producer>Secret Tool</pdf:Producer>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';
	}

	private function build_exif_payload( array $tags ) : string {
		$entry_count = count( $tags );
		$data_offset = 8 + 2 + ( 12 * $entry_count ) + 4;
		$entries     = '';
		$values      = '';

		foreach ( $tags as $tag => $value ) {
			$value    .= "\x00";
			$entries .= pack( 'vvVV', $tag, 2, strlen( $value ), $data_offset + strlen( $values ) );
			$values  .= $value;
		}

		return 'II' . pack( 'vV', 42, 8 ) . pack( 'v', $entry_count ) . $entries . pack( 'V', 0 ) . $values;
	}

	public function test_strips_text_chunk() : void {
		$png    = $this->build_png( [ $this->chunk( 'tEXt', "Author\x00Jane Doe" ) ] );
		$result = Images\strip_png_metadata( $png );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'Jane Doe', $result['data'] );
	}

	public function test_strips_itxt_ztxt_and_exif_chunks() : void {
		$png = $this->build_png( [
			$this->chunk( 'iTXt', "Comment\x00\x00\x00\x00\x00secret" ),
			$this->chunk( 'zTXt', "Software\x00compressed-ish" ),
			$this->chunk( 'eXIf', 'raw-exif-bytes' ),
		] );

		$result = Images\strip_png_metadata( $png );

		$this->assertSame( 3, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'secret', $result['data'] );
		$this->assertStringNotContainsString( 'compressed-ish', $result['data'] );
		$this->assertStringNotContainsString( 'raw-exif-bytes', $result['data'] );
	}

	public function test_strips_modification_time_chunk() : void {
		$png = $this->build_png( [ $this->chunk( 'tIME', pack( 'nCCCCC', 2026, 7, 22, 12, 0, 0 ) ) ] );

		$result = Images\strip_png_metadata( $png );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'tIME', $result['data'] );
	}

	public function test_keeps_ihdr_idat_and_iend_byte_for_byte() : void {
		$png    = $this->build_png( [ $this->chunk( 'tEXt', "x\x00y" ) ] );
		$result = Images\strip_png_metadata( $png );

		$this->assertStringContainsString( 'IHDR', $result['data'] );
		$this->assertStringContainsString( 'not-real-deflate-data', $result['data'] );
		$this->assertStringEndsWith( $this->chunk( 'IEND', '' ), $result['data'] );
	}

	public function test_keeps_rendering_ancillary_chunks() : void {
		$png    = $this->build_png( [
			$this->chunk( 'pHYs', str_repeat( "\x00", 9 ) ),
			$this->chunk( 'gAMA', pack( 'N', 45455 ) ),
		] );
		$result = Images\strip_png_metadata( $png );

		$this->assertSame( 0, $result['stripped_chunks'] );
		$this->assertStringContainsString( 'pHYs', $result['data'] );
		$this->assertStringContainsString( 'gAMA', $result['data'] );
	}

	public function test_keeps_allowlisted_text_keyword() : void {
		$png = $this->build_png( [ $this->chunk( 'tEXt', "Title\x00Public title" ) ] );

		$result = Images\strip_png_metadata( $png );

		$this->assertSame( 0, $result['stripped_chunks'] );
		$this->assertStringContainsString( "Title\x00Public title", $result['data'] );
	}

	public function test_strips_unknown_private_ancillary_chunks() : void {
		$png = $this->build_png( [ $this->chunk( 'meTa', 'private author metadata' ) ] );

		$result = Images\strip_png_metadata( $png );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'meTa', $result['data'] );
		$this->assertStringNotContainsString( 'private author metadata', $result['data'] );
		$this->assertSame( $result['data'], Images\strip_png_metadata( $result['data'] )['data'] );
	}

	public function test_filters_uncompressed_xmp_itxt_to_the_allowlist() : void {
		$itxt   = Images\PNG_XMP_KEYWORD . "\x00\x00\x00\x00\x00" . $this->xmp_packet();
		$png    = $this->build_png( [ $this->chunk( 'iTXt', $itxt ) ] );
		$result = Images\strip_png_metadata( $png );

		$this->assertStringContainsString( 'en-GB', $result['data'] );
		$this->assertStringContainsString( 'Example Publisher', $result['data'] );
		$this->assertStringContainsString( 'VersionID', $result['data'] );
		$this->assertStringNotContainsString( 'Secret Tool', $result['data'] );
		$this->assertStringNotContainsString( 'pdf:Producer', $result['data'] );
		$this->assertSame( $result['data'], Images\strip_png_metadata( $result['data'] )['data'] );
	}

	public function test_decodes_compressed_xmp_itxt_and_rewrites_it_uncompressed() : void {
		$itxt   = Images\PNG_XMP_KEYWORD . "\x00\x01\x00\x00\x00" . gzcompress( $this->xmp_packet() );
		$png    = $this->build_png( [ $this->chunk( 'iTXt', $itxt ) ] );
		$result = Images\strip_png_metadata( $png );

		$this->assertStringContainsString( Images\PNG_XMP_KEYWORD . "\x00\x00\x00\x00\x00", $result['data'] );
		$this->assertStringContainsString( 'Example Publisher', $result['data'] );
	}

	public function test_converts_allowlisted_exif_values_to_xmp_itxt() : void {
		$exif = $this->build_exif_payload( [
			0x010E => 'Allowed PNG title',
			0x0132 => '2026:07:22 12:34:56',
			0x8298 => 'Copyright from PNG',
		] );
		$png    = $this->build_png( [ $this->chunk( 'eXIf', $exif ) ] );
		$result = Images\strip_png_metadata( $png );

		$this->assertStringNotContainsString( 'eXIf', $result['data'] );
		$this->assertStringContainsString( Images\PNG_XMP_KEYWORD, $result['data'] );
		$this->assertStringContainsString( 'Allowed PNG title', $result['data'] );
		$this->assertStringContainsString( 'Copyright from PNG', $result['data'] );
		$this->assertStringContainsString( '2026-07-22T12:34:56', $result['data'] );
	}

	public function test_idempotent_on_already_clean_output() : void {
		$png      = $this->build_png( [ $this->chunk( 'tEXt', "a\x00b" ) ] );
		$first    = Images\strip_png_metadata( $png );
		$second   = Images\strip_png_metadata( $first['data'] );

		$this->assertSame( 0, $second['stripped_chunks'] );
		$this->assertSame( $first['data'], $second['data'] );
	}

	public function test_rejects_bad_signature() : void {
		$this->expectException( \RuntimeException::class );
		Images\strip_png_metadata( 'not a png' );
	}

	public function test_rejects_missing_iend() : void {
		$ihdr = $this->chunk( 'IHDR', str_repeat( "\x00", 13 ) );

		$this->expectException( \RuntimeException::class );
		Images\strip_png_metadata( Images\PNG_SIGNATURE . $ihdr );
	}
}
