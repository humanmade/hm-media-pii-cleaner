<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Dispatcher;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Limits;

/**
 * Tests for resource budgeting and capped file access.
 */
class ResourceLimitsTest extends TestCase {

	public function test_parses_php_ini_byte_values() : void {
		$this->assertSame( -1, Limits\parse_ini_bytes( '-1' ) );
		$this->assertSame( 512 * 1024, Limits\parse_ini_bytes( '512K' ) );
		$this->assertSame( 128 * 1024 * 1024, Limits\parse_ini_bytes( '128M' ) );
		$this->assertSame( 2 * 1024 * 1024 * 1024, Limits\parse_ini_bytes( '2G' ) );
	}

	public function test_effective_limit_reserves_memory_for_parser_copies() : void {
		$memory_limit = 128 * 1024 * 1024;
		$memory_usage = 16 * 1024 * 1024;

		$this->assertSame(
			24 * 1024 * 1024,
			Limits\processing_limit_for_mime( 'image/jpeg', $memory_limit, $memory_usage )
		);
		$this->assertSame(
			16 * 1024 * 1024,
			Limits\processing_limit_for_mime( HM_MEDIA_PII_CLEANER_PDF_MIME, $memory_limit, $memory_usage )
		);
	}

	public function test_upload_prefilter_rejects_covered_file_over_hard_limit() : void {
		$file = Dispatcher\reject_unsafe_upload(
			[
				'name'     => 'oversized.jpg',
				'tmp_name' => '/missing-upload-temp-file',
				'type'     => 'image/jpeg',
				'size'     => HM_MEDIA_PII_CLEANER_MAX_IMAGE_BYTES + 1,
			]
		);

		$this->assertArrayHasKey( 'error', $file );
		$this->assertStringContainsString( 'cannot be safely sanitized', $file['error'] );
	}

	public function test_upload_prefilter_leaves_uncovered_file_unchanged() : void {
		$file = [
			'name'     => 'notes.txt',
			'tmp_name' => '/missing-upload-temp-file',
			'type'     => 'text/plain',
			'size'     => HM_MEDIA_PII_CLEANER_MAX_IMAGE_BYTES + 1,
		];

		$this->assertSame( $file, Dispatcher\reject_unsafe_upload( $file ) );
	}

	public function test_capped_reader_and_atomic_writer_round_trip() : void {
		$directory = sys_get_temp_dir() . '/hmpc-resource-' . wp_generate_uuid4();
		mkdir( $directory );
		$path = $directory . '/image.jpg';

		try {
			$this->assertTrue( Files\write_atomically( $path, 'bounded data' ) );
			$this->assertSame( 'bounded data', Limits\read_file( $path, 'image/jpeg' ) );
			$this->assertSame( [], glob( $directory . '/.hm-media-pii-cleaner-*.tmp' ) );
		} finally {
			@unlink( $path );
			@rmdir( $directory );
		}
	}

	public function test_atomic_copy_does_not_require_a_full_file_buffer() : void {
		$directory = sys_get_temp_dir() . '/hmpc-copy-' . wp_generate_uuid4();
		mkdir( $directory );
		$source      = $directory . '/source.bin';
		$destination = $directory . '/destination.bin';
		file_put_contents( $source, str_repeat( 'bounded-copy-data', 128 ) );

		try {
			$this->assertTrue( Files\copy_file_atomically( $source, $destination ) );
			$this->assertSame( hash_file( 'sha256', $source ), hash_file( 'sha256', $destination ) );
			$this->assertFileExists( $source );
		} finally {
			@unlink( $destination );
			@unlink( $source );
			@rmdir( $directory );
		}
	}

	public function test_last_resort_delete_checks_the_filesystem_not_a_void_wordpress_return() : void {
		$path = sys_get_temp_dir() . '/hmpc-delete-' . wp_generate_uuid4() . '.bin';
		file_put_contents( $path, 'unsafe data' );

		$result = Files\delete_unquarantinable_files( [ $path ] );

		$this->assertTrue( $result['ok'], $result['detail'] );
		$this->assertFileDoesNotExist( $path );
	}
}
