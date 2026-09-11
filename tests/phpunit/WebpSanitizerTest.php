<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Images;

/**
 * Tests for Images\strip_webp_metadata().
 */
class WebpSanitizerTest extends TestCase {

	private function chunk( string $fourcc, string $data ) : string {
		$padding = strlen( $data ) % 2 === 1 ? "\x00" : '';
		return $fourcc . pack( 'V', strlen( $data ) ) . $data . $padding;
	}

	private function build_webp( array $chunks ) : string {
		$body = 'WEBP' . implode( '', $chunks );
		return 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
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

	public function test_strips_exif_chunk() : void {
		$vp8    = $this->chunk( 'VP8 ', 'fake-vp8-image-data' );
		$exif   = $this->chunk( 'EXIF', 'fake-exif-payload' );
		$webp   = $this->build_webp( [ $vp8, $exif ] );
		$result = Images\strip_webp_metadata( $webp );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'fake-exif-payload', $result['data'] );
		$this->assertStringContainsString( 'fake-vp8-image-data', $result['data'] );
	}

	public function test_strips_xmp_chunk() : void {
		$vp8    = $this->chunk( 'VP8L', 'fake-lossless-data' );
		$xmp    = $this->chunk( 'XMP ', '<x:xmpmeta>leaky</x:xmpmeta>' );
		$webp   = $this->build_webp( [ $vp8, $xmp ] );
		$result = Images\strip_webp_metadata( $webp );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'leaky', $result['data'] );
	}

	public function test_rewrites_riff_size_field_correctly() : void {
		$vp8    = $this->chunk( 'VP8 ', 'image-data' );
		$exif   = $this->chunk( 'EXIF', 'metadata-to-remove' );
		$webp   = $this->build_webp( [ $vp8, $exif ] );
		$result = Images\strip_webp_metadata( $webp );

		$declared_size = unpack( 'V', substr( $result['data'], 4, 4 ) )[1];
		$this->assertSame( strlen( $result['data'] ) - 8, $declared_size );
	}

	public function test_idempotent_and_re_parseable() : void {
		$vp8      = $this->chunk( 'VP8 ', 'image-data' );
		$exif     = $this->chunk( 'EXIF', 'metadata' );
		$webp     = $this->build_webp( [ $vp8, $exif ] );
		$first    = Images\strip_webp_metadata( $webp );
		$second   = Images\strip_webp_metadata( $first['data'] );

		$this->assertSame( 0, $second['stripped_chunks'] );
		$this->assertSame( $first['data'], $second['data'] );
	}

	public function test_strips_unknown_top_level_chunk() : void {
		$webp = $this->build_webp( [
			$this->chunk( 'VP8L', 'image-data' ),
			$this->chunk( 'META', 'private author metadata' ),
		] );

		$result = Images\strip_webp_metadata( $webp );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'META', $result['data'] );
		$this->assertStringNotContainsString( 'private author metadata', $result['data'] );
		$this->assertStringContainsString( 'image-data', $result['data'] );
	}

	public function test_strips_unknown_chunk_nested_in_animation_frame() : void {
		$frame = str_repeat( "\x00", 16 )
			. $this->chunk( 'ALPH', 'alpha-data' )
			. $this->chunk( 'VP8 ', 'frame-image-data' )
			. $this->chunk( 'META', 'private frame metadata' );
		$webp = $this->build_webp( [
			$this->chunk( 'VP8X', chr( 0x02 ) . str_repeat( "\x00", 9 ) ),
			$this->chunk( 'ANIM', str_repeat( "\x00", 6 ) ),
			$this->chunk( 'ANMF', $frame ),
		] );

		$result = Images\strip_webp_metadata( $webp );

		$this->assertSame( 1, $result['stripped_chunks'] );
		$this->assertStringNotContainsString( 'private frame metadata', $result['data'] );
		$this->assertStringContainsString( 'alpha-data', $result['data'] );
		$this->assertStringContainsString( 'frame-image-data', $result['data'] );
		$this->assertSame( $result['data'], Images\strip_webp_metadata( $result['data'] )['data'] );
	}

	public function test_rejects_bad_header() : void {
		$this->expectException( \RuntimeException::class );
		Images\strip_webp_metadata( 'not a webp file at all, too short' );
	}

	public function test_preserves_only_structurally_safe_xmp_values() : void {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
			. '<rdf:Description><dc:rights>Copyright 2026</dc:rights>'
			. '<dc:title><pdf:Producer>Secret Tool</pdf:Producer></dc:title>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';
		$webp   = $this->build_webp( [ $this->chunk( 'VP8L', 'image-data' ), $this->chunk( 'XMP ', $xmp ) ] );
		$result = Images\strip_webp_metadata( $webp );

		$this->assertStringContainsString( 'Copyright 2026', $result['data'] );
		$this->assertStringNotContainsString( 'Secret Tool', $result['data'] );
		$this->assertStringNotContainsString( 'pdf:Producer', $result['data'] );
	}

	public function test_converts_allowlisted_exif_values_to_filtered_xmp() : void {
		$vp8x = $this->chunk( 'VP8X', chr( 0x08 ) . str_repeat( "\x00", 9 ) );
		$vp8  = $this->chunk( 'VP8L', 'image-data' );
		$exif = $this->chunk( 'EXIF', $this->build_exif_payload( [
			0x010E => 'Allowed WebP title',
			0x0132 => '2026:07:22 12:34:56',
			0x8298 => 'Copyright from WebP',
		] ) );

		$result = Images\strip_webp_metadata( $this->build_webp( [ $vp8x, $vp8, $exif ] ) );

		$this->assertStringNotContainsString( 'EXIF', $result['data'] );
		$this->assertStringContainsString( 'Allowed WebP title', $result['data'] );
		$this->assertStringContainsString( 'Copyright from WebP', $result['data'] );
		$this->assertStringContainsString( '2026-07-22T12:34:56', $result['data'] );
		$this->assertSame( 0x04, ord( $result['data'][20] ) & 0x0C, 'VP8X must advertise XMP and no longer advertise EXIF.' );
		$this->assertSame( $result['data'], Images\strip_webp_metadata( $result['data'] )['data'] );
	}
}
