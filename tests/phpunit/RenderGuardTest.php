<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\RenderGuard;

/**
 * Tests for RenderGuard\is_attachment_sanitized(), the guard consumers call
 * before exposing a download link.
 */
class RenderGuardTest extends TestCase {

	public function test_uncovered_mime_type_is_always_sanitized() : void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'text/plain' );

		$this->assertTrue( RenderGuard\is_attachment_sanitized( 123 ) );
	}

	public function test_pdf_with_sanitized_status_is_sanitized() : void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_post_meta' )->justReturn( 'sanitized' );

		$this->assertTrue( RenderGuard\is_attachment_sanitized( 123 ) );
	}

	public function test_pdf_with_flagged_status_is_not_sanitized() : void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_post_meta' )->justReturn( 'flagged' );

		$this->assertFalse( RenderGuard\is_attachment_sanitized( 123 ) );
	}

	public function test_pdf_never_processed_fails_open_so_existing_links_keep_working() : void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertTrue( RenderGuard\is_attachment_sanitized( 123 ) );
	}

	public function test_pdf_with_failed_status_is_not_sanitized() : void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_post_meta' )->justReturn( 'failed' );

		$this->assertFalse( RenderGuard\is_attachment_sanitized( 123 ) );
	}

	public function test_flagged_attachment_url_is_suppressed() : void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_post_meta' )->justReturn( 'flagged' );

		$this->assertSame( '', RenderGuard\filter_attachment_url( 'https://example.test/uploads/unsafe.pdf', 123 ) );
	}
}
