<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\Cli;
use HM\MediaPiiCleaner\Dispatcher;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Images;

class ReviewRegressionsTest extends TestCase {

	public function test_mismatched_explicit_id_is_reported_without_processing() : void {
		$cli = \Mockery::mock( 'alias:WP_CLI' );
		$cli->shouldReceive( 'warning' )->once()->with( \Mockery::pattern( '/MIME type.*image\/png/' ) );
		Functions\when( 'get_post' )->justReturn( (object) [ 'ID' => 123, 'post_type' => 'attachment', 'post_mime_type' => 'image/png' ] );
		Functions\expect( 'update_post_meta' )->never();
		$results = new Cli\Results( '' );
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = (object) [ 'queries' => [] ];
		try {
			Cli\walk_attachments( [ '123' ], [], 'application/pdf', function () {
				$this->fail( 'A mismatched file must not reach the sanitizer.' );
			}, $results );
		} finally {
			$GLOBALS['wpdb'] = $previous_wpdb;
		}
		$this->assertSame( 1, $results->total() );
		$this->assertSame( 0, $results->passed() );
	}

	public function test_backup_files_are_sanitized_and_quarantine_uses_the_same_inventory() : void {
		$directory = sys_get_temp_dir() . '/hmpc-review-' . wp_generate_uuid4();
		mkdir( $directory );
		$current = $directory . '/photo-e123.gif';
		$backup = $directory . '/photo.gif';
		// A 1x1 GIF with a private comment immediately before the trailer.
		$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		file_put_contents( $current, $gif );
		file_put_contents( $backup, substr( $gif, 0, -1 ) . "\x21\xFE\x06secret\x00\x3B" );
		Functions\when( 'get_attached_file' )->justReturn( $current );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => '_wp_attachment_backup_sizes' === $key ? [ 'full-orig' => [ 'file' => 'photo.gif' ] ] : '' );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_mkdir_p' )->justReturn( false );
		try {
			Images\sanitize_gif_attachment( 123, [] );
			$this->assertStringNotContainsString( 'secret', file_get_contents( $backup ) );
			$this->assertSame( [ $current, $backup ], array_values( Files\attachment_file_paths( 123, [] ) ) );
			// Force the documented deletion fallback, without writing outside the fixture.
			if ( ! defined( 'ABSPATH' ) ) {
				define( 'ABSPATH', sys_get_temp_dir() . '/hmpc-review-public/' );
			}
			$this->assertTrue( Files\quarantine_attachment( 123 )['ok'] );
			$this->assertFileDoesNotExist( $current );
			$this->assertFileDoesNotExist( $backup );
		} finally {
			foreach ( [ $current, $backup ] as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
			rmdir( $directory );
		}
	}

	public function test_upload_and_backfill_remove_private_stored_metadata() : void {
		$metadata = [
			'width' => 100,
			'sizes' => [ 'thumbnail' => [ 'file' => 'photo-150x150.jpg' ] ],
			'image_meta' => [ 'credit' => 'private-user', 'camera' => 'Secret camera', 'keywords' => [ 'internal' ], 'title' => 'Public title', 'copyright' => 'Public copyright', 'created_timestamp' => 123, 'orientation' => 1 ],
		];
		$expected = $metadata;
		unset( $expected['image_meta']['credit'], $expected['image_meta']['camera'], $expected['image_meta']['keywords'] );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'get_attached_file' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( $metadata );
		Functions\expect( 'wp_update_attachment_metadata' )->once()->with( 123, $expected )->andReturn( true );
		$this->assertSame( $expected, Dispatcher\sanitize_images_before_ewww( $metadata, 123 ) );
		Images\sanitize_stored_image_metadata( 123 );
		$this->assertFalse( Images\filter_attachment_metadata( false ) );
	}

	public function test_metadata_write_filter_is_registered() : void {
		$GLOBALS['hm_test_registered_hooks'] = [];
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Images\bootstrap();
		$this->assertContains(
			[ 'filter', 'wp_update_attachment_metadata', 'HM\\MediaPiiCleaner\\Images\\filter_attachment_metadata', 999, 1 ],
			$GLOBALS['hm_test_registered_hooks']
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_orientation_failure_quarantines_original_bytes_without_rewriting_them() : void {
		$directory = sys_get_temp_dir() . '/hmpc-orientation-' . wp_generate_uuid4();
		mkdir( $directory );
		define( 'ABSPATH', $directory . '/public/' );
		$path = $directory . '/photo.jpg';
		$payload = "Exif\0\0II" . pack( 'vV', 42, 8 ) . pack( 'v', 1 ) . pack( 'vvVv', 0x0112, 3, 1, 6 ) . "\0\0" . pack( 'V', 0 );
		$jpeg = "\xFF\xD8\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . "\xFF\xD9";
		file_put_contents( $path, $jpeg );
		$statuses = [];
		Functions\when( 'get_attached_file' )->justReturn( $path );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'trailingslashit' )->alias( static fn( $path ) => rtrim( $path, '/' ) . '/' );
		Functions\when( 'wp_unique_filename' )->alias( static fn( $dir, $name ) => $name );
		Functions\when( 'wp_mkdir_p' )->alias( static fn( $path ) => mkdir( $path, 0700, true ) );
		Functions\when( 'update_post_meta' )->alias( static function ( $id, $key, $value ) use ( &$statuses ) {
			$statuses[ $key ] = $value;
		} );
		$quarantine = $directory . '/hm-media-pii-cleaner-quarantine';
		try {
			Images\sanitize_jpeg_attachment( 123, [] );
			$this->assertSame( 'flagged', $statuses[ HM_MEDIA_PII_CLEANER_STATUS_META ] );
			$this->assertStringContainsString( 'pixel normalization', $statuses[ HM_MEDIA_PII_CLEANER_DETAIL_META ] );
			$this->assertFileDoesNotExist( $path );
			$this->assertSame( $jpeg, file_get_contents( $quarantine . '/123/photo.jpg' ) );
		} finally {
			foreach ( [ $path, $quarantine . '/123/photo.jpg' ] as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			foreach ( [ $quarantine . '/123', $quarantine, $directory ] as $dir ) {
				if ( is_dir( $dir ) ) {
					rmdir( $dir );
				}
			}
		}
	}

	public function test_all_nontrivial_exif_orientations_are_rejected_in_both_byte_orders() : void {
		foreach ( [ true, false ] as $little ) {
			foreach ( range( 1, 8 ) as $orientation ) {
				$short = $little ? 'v' : 'n';
				$long = $little ? 'V' : 'N';
				$tiff = ( $little ? 'II' : 'MM' ) . pack( $short, 42 ) . pack( $long, 8 )
					. pack( $short, 1 ) . pack( $short, 0x0112 ) . pack( $short, 3 ) . pack( $long, 1 ) . pack( $short, $orientation ) . "\0\0" . pack( $long, 0 );
				$payload = "Exif\0\0" . $tiff;
				$jpeg = "\xFF\xD8\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . "\xFF\xD9";
				try {
					$result = Images\strip_jpeg_metadata( $jpeg );
					$this->assertSame( 1, $orientation, 'Rotated or mirrored pixels must not lose their transform.' );
					$this->assertStringNotContainsString( 'Exif', $result['data'] );
				} catch ( \RuntimeException $e ) {
					$this->assertNotSame( 1, $orientation );
					$this->assertStringContainsString( 'pixel normalization', $e->getMessage() );
				}
			}
		}
	}
}
