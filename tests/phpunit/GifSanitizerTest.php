<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Images;

/**
 * Tests for Images\strip_gif_metadata().
 */
class GifSanitizerTest extends TestCase {

	/**
	 * Build a minimal single-frame GIF with a Comment Extension, a NETSCAPE2.0
	 * Application Extension, and a foreign Application Extension.
	 */
	private function build_gif( bool $with_comment, bool $with_netscape, bool $with_foreign_app ) : string {
		$out  = 'GIF89a';
		$out .= "\x01\x00\x01\x00\x00\x00\x00"; // 1x1 Logical Screen Descriptor, no GCT.

		if ( $with_comment ) {
			$out .= "\x21\xFE" . "\x05Hello" . "\x00"; // Comment Extension: "Hello".
		}

		if ( $with_netscape ) {
			$out .= "\x21\xFF" . "\x0BNETSCAPE2.0" . "\x03\x01\x00\x00" . "\x00";
		}

		if ( $with_foreign_app ) {
			$out .= "\x21\xFF" . "\x0BAdobe XMP  " . "\x03\x01\x00\x00" . "\x00";
		}

		// Image Descriptor: 1x1, no local color table, LZW min code size 2, one sub-block, terminator.
		$out .= "\x2C" . "\x00\x00\x00\x00\x01\x00\x01\x00\x00";
		$out .= "\x02" . "\x02\x44\x01" . "\x00";

		$out .= "\x3B"; // Trailer.

		return $out;
	}

	public function test_strips_comment_extension() : void {
		$gif    = $this->build_gif( true, false, false );
		$result = Images\strip_gif_metadata( $gif );

		$this->assertSame( 1, $result['stripped_blocks'] );
		$this->assertSame( 1, $result['frame_count'] );
		$this->assertStringNotContainsString( 'Hello', $result['data'] );
	}

	public function test_keeps_netscape_application_extension() : void {
		$gif    = $this->build_gif( false, true, false );
		$result = Images\strip_gif_metadata( $gif );

		$this->assertSame( 0, $result['stripped_blocks'] );
		$this->assertStringContainsString( 'NETSCAPE2.0', $result['data'] );
	}

	public function test_strips_foreign_application_extension() : void {
		$gif    = $this->build_gif( false, false, true );
		$result = Images\strip_gif_metadata( $gif );

		$this->assertSame( 1, $result['stripped_blocks'] );
		$this->assertStringNotContainsString( 'Adobe XMP', $result['data'] );
	}

	public function test_strips_unknown_extension_label() : void {
		$gif = $this->build_gif( false, false, false );
		$payload = 'private metadata';
		$unknown = "\x21\xCE" . chr( strlen( $payload ) ) . $payload . "\x00";
		$gif = substr( $gif, 0, 13 ) . $unknown . substr( $gif, 13 );

		$result = Images\strip_gif_metadata( $gif );

		$this->assertSame( 1, $result['stripped_blocks'] );
		$this->assertStringNotContainsString( 'private metadata', $result['data'] );
		$this->assertSame( 1, $result['frame_count'] );
		$this->assertSame( $result['data'], Images\strip_gif_metadata( $result['data'] )['data'] );
	}

	public function test_frame_count_unchanged_after_stripping_all_metadata() : void {
		$gif      = $this->build_gif( true, true, true );
		$before   = Images\strip_gif_metadata( $gif );
		$reparsed = Images\strip_gif_metadata( $before['data'] );

		$this->assertSame( $before['frame_count'], $reparsed['frame_count'] );
		$this->assertSame( 1, $before['frame_count'] );
	}

	public function test_rejects_bad_header() : void {
		$this->expectException( \RuntimeException::class );
		Images\strip_gif_metadata( 'not a gif' );
	}

	public function test_rejects_truncated_file() : void {
		$gif = $this->build_gif( true, false, false );

		$this->expectException( \RuntimeException::class );
		Images\strip_gif_metadata( substr( $gif, 0, 10 ) );
	}
}
