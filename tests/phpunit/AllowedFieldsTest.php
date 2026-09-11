<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\Fields;
use HM\MediaPiiCleaner\Images;
use HM\MediaPiiCleaner\Ooxml;
use HM\MediaPiiCleaner\Pdf;
use HM\MediaPiiCleaner\XmpFilter;

/**
 * Tests for the hm_media_pii_cleaner_allowed_fields filter and the field
 * registry every format sanitizer reads from.
 */
class AllowedFieldsTest extends TestCase {

	private array $tmp_paths = [];

	protected function tearDown() : void {
		foreach ( $this->tmp_paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		parent::tearDown();
	}

	/**
	 * Apply a modification to the default fields through the public filter.
	 */
	private function use_fields( callable $modify ) : void {
		Filters\expectApplied( Fields\FILTER )->andReturnUsing( $modify );
	}

	private function xmp_packet( string $properties ) : string {
		return '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF'
			. ' xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
			. ' xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/">'
			. '<rdf:Description>' . $properties . '</rdf:Description></rdf:RDF></x:xmpmeta>';
	}

	private function iptc_segment( string $datasets ) : string {
		$resource = '8BIM' . pack( 'n', 0x0404 ) . "\x00\x00" . pack( 'N', strlen( $datasets ) ) . $datasets;
		if ( strlen( $datasets ) % 2 ) {
			$resource .= "\x00";
		}
		$payload = "Photoshop 3.0\x00" . $resource;
		return "\xFF\xED" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	private function iptc_dataset( int $dataset, string $value ) : string {
		return "\x1C\x02" . chr( $dataset ) . pack( 'n', strlen( $value ) ) . $value;
	}

	private function jpeg( string $segments ) : string {
		$sos = "\xFF\xDA" . pack( 'n', 2 ) . 'scan-data';
		return "\xFF\xD8" . $segments . $sos . "\xFF\xD9";
	}

	/**
	 * Write a real single-page PDF with the given /Info Author and optional
	 * Catalog XMP, the same way the PDF verifier tests do.
	 */
	private function make_pdf( string $author, string $xmp = '' ) : string {
		$pdf = new class( $xmp ) extends \setasign\Fpdi\Fpdi {
			private string $document_xmp;
			private ?int $metadata_object_number = null;

			public function __construct( string $document_xmp ) {
				parent::__construct();
				$this->document_xmp = $document_xmp;
				unset( $this->metadata['Producer'] );
			}

			protected function _putresources() {
				parent::_putresources();
				if ( '' === $this->document_xmp ) {
					return;
				}
				$this->_newobj();
				$this->metadata_object_number = $this->n;
				$this->_put( '<</Type /Metadata /Subtype /XML /Length ' . strlen( $this->document_xmp ) . '>>' );
				$this->_putstream( $this->document_xmp );
				$this->_put( 'endobj' );
			}

			protected function _putcatalog() {
				parent::_putcatalog();
				if ( null !== $this->metadata_object_number ) {
					$this->_put( '/Metadata ' . $this->metadata_object_number . ' 0 R' );
				}
			}
		};
		$pdf->SetAuthor( $author );
		$pdf->AddPage();
		$pdf->SetFont( 'Helvetica' );
		$pdf->Cell( 0, 10, 'Allowed fields test' );

		$path              = tempnam( sys_get_temp_dir(), 'hmpc-fields-' ) . '.pdf';
		$this->tmp_paths[] = $path;
		file_put_contents( $path, $pdf->Output( 'S' ) );

		return $path;
	}

	public function test_default_fields_pass_validation_unchanged() : void {
		$this->assertSame( Fields\default_fields(), Fields\get_fields() );
	}

	public function test_non_array_filter_result_keeps_nothing() : void {
		Functions\expect( '_doing_it_wrong' )->atLeast()->once();
		$this->use_fields( static fn() => null );

		$this->assertSame( [], Fields\get_fields() );
		$this->assertSame( XmpFilter\EMPTY_PACKET, XmpFilter\filter_xmp_packet( $this->xmp_packet( '<dc:title>Title</dc:title>' ) ) );
	}

	public function test_invalid_mappings_are_dropped_and_reported() : void {
		Functions\expect( '_doing_it_wrong' )->atLeast()->times( 8 );
		$this->use_fields( static function ( array $fields ) : array {
			$fields['broken'] = [
				'xmp'        => [
					[ 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about', 'rdf' ],
					[ 'http://example.com/ns/', 'value', 'x' ],
					[ 'http://example.com/ns/', 'value', '1bad' ],
				],
				'png'        => [ 'XML:com.adobe.xmp' ],
				'pdf_info'   => [ 'Producer' ],
				'ooxml_core' => [ [ 'http://example.com/ns/', 'value' ] ],
				'unknown'    => [ 'value' ],
			];
			$fields['Bad-ID'] = [ 'image_meta' => [ 'camera' ] ];
			return $fields;
		} );

		$fields = Fields\get_fields();

		$this->assertSame( [], $fields['broken'] );
		$this->assertArrayNotHasKey( 'Bad-ID', $fields );
		$this->assertNotContains( 'Producer', Fields\pdf_info_keys( $fields ) );
		$this->assertNotContains( 'XML:com.adobe.xmp', Fields\png_keywords( $fields ) );
	}

	public function test_prefix_cannot_be_bound_to_a_second_namespace() : void {
		Functions\expect( '_doing_it_wrong' )->once();
		$this->use_fields( static function ( array $fields ) : array {
			$fields['clash'] = [ 'xmp' => [ [ 'http://example.com/other/', 'title', 'dc' ] ] ];
			return $fields;
		} );

		$this->assertSame( [], Fields\get_fields()['clash'] );
	}

	public function test_removed_field_is_stripped_from_every_format() : void {
		$this->use_fields( static function ( array $fields ) : array {
			unset( $fields['copyright'] );
			return $fields;
		} );

		$xmp = XmpFilter\filter_xmp_packet( $this->xmp_packet( '<dc:title>Kept title</dc:title><dc:rights>Copyright 2026</dc:rights>' ) );
		$this->assertStringContainsString( 'Kept title', $xmp );
		$this->assertStringNotContainsString( 'Copyright 2026', $xmp );

		$this->assertTrue( Images\is_allowed_text_chunk( 'tEXt', "Title\x00Kept" ) );
		$this->assertFalse( Images\is_allowed_text_chunk( 'tEXt', "Copyright\x00Copyright 2026" ) );

		$exif_values = Images\extract_allowed_values_from_exif_sections( [ 'IFD0' => [ 'Copyright' => 'Copyright 2026', 'ImageDescription' => 'Kept title' ] ] );
		$this->assertSame( [ 'title' => 'Kept title' ], $exif_values );

		$core = Ooxml\filter_core_properties(
			'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Kept title</dc:title><dc:rights>Copyright 2026</dc:rights></cp:coreProperties>'
		);
		$this->assertStringContainsString( 'Kept title', $core );
		$this->assertStringNotContainsString( 'Copyright 2026', $core );

		$metadata = Images\filter_attachment_metadata( [ 'image_meta' => [ 'title' => 'Kept title', 'copyright' => 'Copyright 2026', 'orientation' => '1' ] ] );
		$this->assertSame( [ 'title' => 'Kept title', 'orientation' => '1' ], $metadata['image_meta'] );
	}

	public function test_orientation_is_kept_even_when_image_meta_mappings_are_removed() : void {
		$this->use_fields( static fn() => [] );

		$metadata = Images\filter_attachment_metadata( [ 'image_meta' => [ 'title' => 'Title', 'orientation' => '1' ] ] );

		$this->assertSame( [ 'orientation' => '1' ], $metadata['image_meta'] );
	}

	public function test_added_xmp_field_survives_xmp_jpeg_and_svg() : void {
		$this->use_fields( static function ( array $fields ) : array {
			$fields['credit'] = [ 'xmp' => [ [ 'http://ns.adobe.com/photoshop/1.0/', 'Credit', 'photoshop' ] ] ];
			return $fields;
		} );
		$packet = $this->xmp_packet( '<photoshop:Credit>Agency credit</photoshop:Credit><photoshop:History>Private edit log</photoshop:History>' );

		$xmp = XmpFilter\filter_xmp_packet( $packet );
		$this->assertStringContainsString( '>Agency credit</photoshop:Credit>', $xmp );
		$this->assertStringNotContainsString( 'Private edit log', $xmp );

		$app1 = "http://ns.adobe.com/xap/1.0/\x00" . $packet;
		$jpeg = Images\strip_jpeg_metadata( $this->jpeg( "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 ) );
		$this->assertStringContainsString( 'Agency credit', $jpeg['data'] );
		$this->assertStringNotContainsString( 'Private edit log', $jpeg['data'] );

		$svg = Images\strip_svg_metadata( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><metadata>' . $packet . '</metadata></svg>' );
		$this->assertStringContainsString( 'Agency credit', $svg );
		$this->assertStringNotContainsString( 'Private edit log', $svg );
	}

	public function test_added_exif_and_iptc_mappings_convert_into_the_field_xmp_property() : void {
		$this->use_fields( static function ( array $fields ) : array {
			$fields['creator'] = [
				'xmp'  => [ [ 'http://purl.org/dc/elements/1.1/', 'creator', 'dc' ] ],
				'exif' => [ 'Artist' ],
				'iptc' => [ '2#080' ],
			];
			return $fields;
		} );

		$values = Images\extract_allowed_values_from_exif_sections( [ 'IFD0' => [ 'Artist' => 'EXIF Photographer' ] ] );
		$this->assertSame( [ 'creator' => 'EXIF Photographer' ], $values );
		$this->assertStringContainsString( '>EXIF Photographer</dc:creator>', Images\build_xmp_packet_from_values( $values ) );

		$jpeg = Images\strip_jpeg_metadata( $this->jpeg( $this->iptc_segment( $this->iptc_dataset( 80, 'IPTC Photographer' ) ) ) );
		$this->assertStringNotContainsString( 'Photoshop 3.0', $jpeg['data'] );
		$this->assertStringContainsString( 'IPTC Photographer', $jpeg['data'] );
	}

	public function test_exif_and_iptc_values_merge_without_duplicating_a_shared_xmp_target() : void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is required to create a valid JPEG fixture.' );
		}
		$this->use_fields( static function ( array $fields ) : array {
			$creator            = [ 'http://purl.org/dc/elements/1.1/', 'creator', 'dc' ];
			$fields['creator']  = [ 'xmp' => [ $creator ], 'exif' => [ 'Artist' ], 'iptc' => [ '2#080' ] ];
			$fields['byline']   = [ 'xmp' => [ $creator ], 'exif' => [ 'Artist' ] ];
			return $fields;
		} );

		$image = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagejpeg( $image );
		$jpeg = ob_get_clean();

		$value = "EXIF Photographer\x00";
		$tiff  = 'II' . pack( 'vV', 42, 8 ) . pack( 'v', 1 ) . pack( 'vvVV', 0x013B, 2, strlen( $value ), 26 ) . pack( 'V', 0 ) . $value;
		$app1  = "Exif\x00\x00" . $tiff;
		$exif  = "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1;
		$iptc  = $this->iptc_segment( $this->iptc_dataset( 80, 'IPTC Photographer' ) );
		$jpeg  = substr( $jpeg, 0, 2 ) . $exif . $iptc . substr( $jpeg, 2 );

		$result = Images\strip_jpeg_metadata( $jpeg );

		$this->assertSame( 1, substr_count( $result['data'], 'EXIF Photographer' ) );
		$this->assertSame( 1, substr_count( $result['data'], 'IPTC Photographer' ) );
		$this->assertSame( $result['data'], Images\strip_jpeg_metadata( $result['data'] )['data'] );
	}

	public function test_modified_png_keyword_mapping_replaces_the_default() : void {
		$this->use_fields( static function ( array $fields ) : array {
			$fields['title']['png'] = [ 'Description' ];
			return $fields;
		} );

		$this->assertTrue( Images\is_allowed_text_chunk( 'tEXt', "Description\x00Kept" ) );
		$this->assertFalse( Images\is_allowed_text_chunk( 'tEXt', "Title\x00Dropped" ) );
	}

	public function test_added_ooxml_properties_are_kept() : void {
		$this->use_fields( static function ( array $fields ) : array {
			$fields['category'] = [ 'ooxml_core' => [ [ 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties', 'category' ] ] ];
			$fields['manager']  = [ 'ooxml_app' => [ 'Manager' ] ];
			return $fields;
		} );

		$core = Ooxml\filter_core_properties(
			'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"><cp:category>Guides</cp:category><dc:creator>jdoe</dc:creator></cp:coreProperties>'
		);
		$this->assertStringContainsString( '<cp:category>Guides</cp:category>', $core );
		$this->assertStringNotContainsString( 'jdoe', $core );

		$app = Ooxml\filter_app_properties(
			'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
			. '<Application>Secret Office</Application><Company>Example Publisher</Company><Manager>Team Lead</Manager></Properties>'
		);
		$this->assertStringContainsString( '<Company>Example Publisher</Company>', $app );
		$this->assertStringContainsString( '<Manager>Team Lead</Manager>', $app );
		$this->assertStringNotContainsString( 'Secret Office', $app );
	}

	public function test_pdf_author_is_dropped_by_default() : void {
		$path    = $this->make_pdf( 'Jane Author' );
		$rebuilt = Pdf\rebuild_pdf( $path );

		$this->assertStringNotContainsString( 'Jane Author', $rebuilt['data'] );
	}

	public function test_allowed_pdf_author_and_dc_creator_pass_rebuild_and_verification() : void {
		$this->use_fields( static function ( array $fields ) : array {
			$fields['author'] = [
				'xmp'      => [ [ 'http://purl.org/dc/elements/1.1/', 'creator', 'dc' ] ],
				'pdf_info' => [ 'Author' ],
			];
			return $fields;
		} );
		$path = $this->make_pdf( 'Jane Author', $this->xmp_packet( '<dc:creator>XMP Author</dc:creator><photoshop:History>Private edit log</photoshop:History>' ) );

		$rebuilt = Pdf\rebuild_pdf( $path );
		$final   = Pdf\strip_metadata_streams( $rebuilt['data'] );

		$this->assertStringContainsString( 'Jane Author', $final['data'] );
		$this->assertStringContainsString( 'XMP Author', $final['data'] );
		$this->assertStringNotContainsString( 'Private edit log', $final['data'] );
		$this->assertSame( [ 'ok' => true, 'reason' => '' ], Pdf\verify( $path, $rebuilt['data'], $final['data'] ) );
	}

	public function test_verifier_still_rejects_fields_that_are_not_allowed() : void {
		$data = '%PDF-1.4 <dc:creator>Private Person</dc:creator>';
		$this->assertStringContainsString( 'dc:creator', Pdf\verify( '/dev/null', $data, $data )['reason'] );

		$data = '%PDF-1.4 /Author (Private Person)';
		$this->assertStringContainsString( 'Info dictionary', Pdf\verify( '/dev/null', $data, $data )['reason'] );
	}
}
