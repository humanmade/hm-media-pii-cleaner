<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Images;

/**
 * Tests for Images\strip_jpeg_metadata().
 */
class JpegSanitizerTest extends TestCase {

	/**
	 * Build a length-prefixed marker segment (marker byte + big-endian
	 * length-including-itself + payload).
	 */
	private function segment( int $marker_id, string $payload ) : string {
		return "\xFF" . chr( $marker_id ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	/**
	 * Build a minimal JPEG: SOI, the given marker segments, a bare SOS
	 * segment, dummy scan data, and EOI.
	 */
	private function build_jpeg( array $segments ) : string {
		$sos = "\xFF\xDA" . pack( 'n', 2 ) . 'scan-data-payload-follows-scan-data-payload';
		return "\xFF\xD8" . implode( '', $segments ) . $sos . "\xFF\xD9";
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

		$tiff = 'II' . pack( 'vV', 42, 8 ) . pack( 'v', $entry_count ) . $entries . pack( 'V', 0 ) . $values;
		return "Exif\x00\x00" . $tiff;
	}

	private function iptc_dataset( int $dataset, string $value ) : string {
		return "\x1C\x02" . chr( $dataset ) . pack( 'n', strlen( $value ) ) . $value;
	}

	private function build_iptc_payload( string $datasets ) : string {
		$resource = '8BIM' . pack( 'n', 0x0404 ) . "\x00\x00" . pack( 'N', strlen( $datasets ) ) . $datasets;
		if ( strlen( $datasets ) % 2 ) {
			$resource .= "\x00";
		}
		return "Photoshop 3.0\x00" . $resource;
	}

	public function test_strips_app1_exif_segment() : void {
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xE1, "Exif\x00\x00fake-exif-Jane-Doe" ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 1, $result['stripped_segments'] );
		$this->assertStringNotContainsString( 'Jane-Doe', $result['data'] );
	}

	public function test_strips_app13_iptc_segment() : void {
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xED, 'Photoshop 3.0' . "\x00" . 'fake-iptc-credit' ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 1, $result['stripped_segments'] );
		$this->assertStringNotContainsString( 'fake-iptc-credit', $result['data'] );
	}

	public function test_strips_comment_segment() : void {
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xFE, 'Created by SecretTool 3.0' ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 1, $result['stripped_segments'] );
		$this->assertStringNotContainsString( 'SecretTool', $result['data'] );
	}

	public function test_strips_app12_and_unknown_app_metadata() : void {
		$jpeg = $this->build_jpeg( [
			$this->segment( 0xEC, 'Secret camera metadata' ),
			$this->segment( 0xE3, 'Unknown private metadata' ),
			$this->segment( 0xE2, 'Non-ICC APP2 metadata' ),
		] );

		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 3, $result['stripped_segments'] );
		$this->assertStringNotContainsString( 'Secret camera metadata', $result['data'] );
		$this->assertStringNotContainsString( 'Unknown private metadata', $result['data'] );
		$this->assertStringNotContainsString( 'Non-ICC APP2 metadata', $result['data'] );
	}

	public function test_keeps_app0_jfif_segment() : void {
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 0, $result['stripped_segments'] );
		$this->assertStringContainsString( 'JFIF', $result['data'] );
	}

	public function test_keeps_rendering_relevant_icc_and_adobe_app_segments() : void {
		$jpeg = $this->build_jpeg( [
			$this->segment( 0xE2, Images\JPEG_ICC_SIGNATURE . "\x01\x01profile-data" ),
			$this->segment( 0xEE, 'Adobe' . "\x00dct-color-transform" ),
		] );

		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 0, $result['stripped_segments'] );
		$this->assertStringContainsString( 'profile-data', $result['data'] );
		$this->assertStringContainsString( 'dct-color-transform', $result['data'] );
	}

	public function test_keeps_scan_data_untouched() : void {
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xE1, "Exif\x00\x00leak" ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertStringContainsString( 'scan-data-payload', $result['data'] );
	}

	public function test_strips_metadata_after_scan_without_corrupting_stuffed_bytes_or_restart_markers() : void {
		$scan = "scan\xFF\x00literal-ff\xFF\xD0after-restart";
		$jpeg = "\xFF\xD8\xFF\xDA" . pack( 'n', 2 ) . $scan
			. $this->segment( 0xFE, 'Late SecretTool metadata' ) . "\xFF\xD9";

		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 1, $result['stripped_segments'] );
		$this->assertStringNotContainsString( 'Late SecretTool metadata', $result['data'] );
		$this->assertStringContainsString( $scan, $result['data'] );
		$this->assertSame( $result['data'], Images\strip_jpeg_metadata( $result['data'] )['data'] );
	}

	public function test_idempotent_on_already_clean_output() : void {
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xE1, "Exif\x00\x00leak" ) ] );
		$first  = Images\strip_jpeg_metadata( $jpeg );
		$second = Images\strip_jpeg_metadata( $first['data'] );

		$this->assertSame( 0, $second['stripped_segments'] );
		$this->assertSame( $first['data'], $second['data'] );
	}

	public function test_rejects_bad_soi() : void {
		$this->expectException( \RuntimeException::class );
		Images\strip_jpeg_metadata( 'not a jpeg' );
	}

	public function test_preserves_only_structurally_safe_xmp_values() : void {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
			. '<rdf:Description><dc:title>Public title</dc:title>'
			. '<dc:rights pdf:Producer="Secret Tool">Copyright 2026</dc:rights>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xE1, Images\JPEG_XMP_SIGNATURE . $xmp ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertStringContainsString( 'Public title', $result['data'] );
		$this->assertStringNotContainsString( 'Secret Tool', $result['data'] );
		$this->assertStringNotContainsString( 'Copyright 2026', $result['data'] );
	}

	public function test_converts_allowlisted_exif_values_to_filtered_xmp() : void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is required to create a valid JPEG fixture.' );
		}

		$image = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagejpeg( $image );
		$jpeg = ob_get_clean();

		$exif = $this->segment(
			0xE1,
			$this->build_exif_payload( [
				0x010E => 'Allowed EXIF title',
				0x0132 => '2026:07:22 12:34:56',
				0x8298 => 'Copyright from EXIF',
			] )
		);
		$jpeg = substr( $jpeg, 0, 2 ) . $exif . substr( $jpeg, 2 );

		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertStringNotContainsString( "Exif\x00\x00", $result['data'] );
		$this->assertStringContainsString( 'Allowed EXIF title', $result['data'] );
		$this->assertStringContainsString( 'Copyright from EXIF', $result['data'] );
		$this->assertStringContainsString( '2026-07-22T12:34:56', $result['data'] );
		$this->assertSame( $result['data'], Images\strip_jpeg_metadata( $result['data'] )['data'] );
	}

	public function test_converts_allowlisted_iptc_values_to_filtered_xmp() : void {
		$payload = $this->build_iptc_payload(
			$this->iptc_dataset( 5, 'Allowed IPTC title' )
			. $this->iptc_dataset( 55, '20260722' )
			. $this->iptc_dataset( 60, '123456+0300' )
			. $this->iptc_dataset( 116, 'Copyright from IPTC' )
			. $this->iptc_dataset( 135, 'en-GB' )
		);
		$jpeg   = $this->build_jpeg( [ $this->segment( 0xED, $payload ) ] );
		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertStringNotContainsString( 'Photoshop 3.0', $result['data'] );
		$this->assertStringContainsString( 'Allowed IPTC title', $result['data'] );
		$this->assertStringContainsString( 'Copyright from IPTC', $result['data'] );
		$this->assertStringContainsString( '2026-07-22T12:34:56+03:00', $result['data'] );
		$this->assertStringContainsString( 'en-GB', $result['data'] );
	}
}
