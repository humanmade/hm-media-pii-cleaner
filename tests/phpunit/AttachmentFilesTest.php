<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\Files;
use HM\MediaPiiCleaner\Images;

class AttachmentFilesTest extends TestCase {

	public function test_includes_wordpress_retained_original_image() : void {
		Functions\when( 'get_post_meta' )->justReturn( [] );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/2026/07/photo-scaled.jpg' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ) : string => basename( $path ) );

		$paths = Files\attachment_file_paths(
			123,
			[
				'original_image' => 'photo.jpg',
				'sizes'          => [
					'thumbnail' => [ 'file' => 'photo-150x150.jpg' ],
				],
			]
		);

		$this->assertSame(
			[
				'/uploads/2026/07/photo-scaled.jpg',
				'/uploads/2026/07/photo.jpg',
				'/uploads/2026/07/photo-150x150.jpg',
			],
			$paths
		);
	}

	public function test_sanitizes_pdf_preview_jpegs_but_not_non_jpeg_sizes() : void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is required to create a valid JPEG fixture.' );
		}

		$directory = sys_get_temp_dir() . '/hmpc-pdf-preview-' . wp_generate_uuid4();
		mkdir( $directory );
		$pdf_path     = $directory . '/document.pdf';
		$preview_path = $directory . '/document-pdf.jpg';
		file_put_contents( $pdf_path, '%PDF-1.4' );

		$image = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagejpeg( $image );
		$jpeg = ob_get_clean();
		$late_comment = "\xFF\xFE" . pack( 'n', strlen( 'PDF preview secret' ) + 2 ) . 'PDF preview secret';
		file_put_contents( $preview_path, substr( $jpeg, 0, -2 ) . $late_comment . "\xFF\xD9" );

		Functions\when( 'get_attached_file' )->justReturn( $pdf_path );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ) : string => basename( $path ) );

		try {
			$result = Images\sanitize_pdf_preview_images(
				456,
				[
					'sizes' => [
						'full'  => [ 'file' => 'document-pdf.jpg', 'mime-type' => 'image/jpeg' ],
						'other' => [ 'file' => 'document-preview.png', 'mime-type' => 'image/png' ],
					],
				]
			);

			$this->assertTrue( $result['ok'], $result['detail'] );
			$this->assertStringNotContainsString( 'PDF preview secret', file_get_contents( $preview_path ) );
			$this->assertStringContainsString( 'metadata segment', $result['detail'] );
			$this->assertStringContainsString( '1 PDF preview JPEG', $result['detail'] );
			$this->assertSame( [ $preview_path ], Images\get_pdf_preview_file_paths( 456, [
				'sizes' => [
					'full'  => [ 'file' => 'document-pdf.jpg', 'mime-type' => 'image/jpeg' ],
					'other' => [ 'file' => 'document-preview.png', 'mime-type' => 'image/png' ],
				],
			] ) );
		} finally {
			@unlink( $preview_path );
			@unlink( $pdf_path );
			@rmdir( $directory );
		}
	}

	public function test_removes_pdf_preview_paths_from_active_metadata_on_failure() : void {
		$directory = sys_get_temp_dir() . '/hmpc-pdf-preview-remove-' . wp_generate_uuid4();
		mkdir( $directory );
		$pdf_path     = $directory . '/document.pdf';
		$preview_path = $directory . '/document-pdf.jpg';
		file_put_contents( $pdf_path, '%PDF-1.4' );
		file_put_contents( $preview_path, 'unsafe preview' );

		Functions\when( 'get_attached_file' )->justReturn( $pdf_path );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ) : string => basename( $path ) );

		try {
			$this->assertTrue( Images\remove_pdf_preview_images( 457, [
				'sizes' => [
					'full' => [ 'file' => 'document-pdf.jpg', 'mime-type' => 'image/jpeg' ],
				],
			] ) );
			$this->assertFileDoesNotExist( $preview_path );
		} finally {
			@unlink( $preview_path );
			@unlink( $pdf_path );
			@rmdir( $directory );
		}
	}

	public function test_chunked_image_with_unreadable_file_is_flagged() : void {
		$statuses = [];
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/missing.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $attachment_id, string $key, string $value ) use ( &$statuses ) : void {
				$statuses[ $key ] = $value;
			}
		);

		Images\strip_chunked_image_attachment(
			321,
			[],
			static fn( string $data ) : array => [ 'data' => $data, 'stripped_chunks' => 0 ],
			'stripped_chunks',
			'PNG'
		);

		$this->assertSame( HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $statuses[ HM_MEDIA_PII_CLEANER_STATUS_META ] );
	}

	public function test_gif_with_unreadable_file_is_flagged() : void {
		$statuses = [];
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/missing.gif' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $attachment_id, string $key, string $value ) use ( &$statuses ) : void {
				$statuses[ $key ] = $value;
			}
		);

		Images\sanitize_gif_attachment( 322, [] );

		$this->assertSame( HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $statuses[ HM_MEDIA_PII_CLEANER_STATUS_META ] );
	}

	public function test_svg_with_unreadable_file_is_flagged() : void {
		$statuses = [];
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/missing.svg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $attachment_id, string $key, string $value ) use ( &$statuses ) : void {
				$statuses[ $key ] = $value;
			}
		);

		Images\sanitize_svg_attachment( 323 );

		$this->assertSame( HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $statuses[ HM_MEDIA_PII_CLEANER_STATUS_META ] );
	}
}
