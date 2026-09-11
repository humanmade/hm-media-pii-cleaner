<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Pdf;

/**
 * Tests for Pdf\verify() — the mandatory gate before a PDF is trusted as sanitized.
 */
class PdfVerifierTest extends TestCase {

	private ?string $tmp_pdf_path = null;

	protected function tearDown() : void {
		if ( $this->tmp_pdf_path && is_file( $this->tmp_pdf_path ) ) {
			unlink( $this->tmp_pdf_path );
		}
		parent::tearDown();
	}

	/**
	 * Build a real, minimal, valid single-page PDF via the same FPDF writer
	 * Stage 1 uses, and write it to a temp file (Pdf\verify() re-parses the
	 * source path from disk).
	 */
	private function make_real_pdf( bool $remove_producer = true ) : array {
		$pdf = new class() extends \setasign\Fpdi\Fpdi {
			public function remove_producer_metadata() : void {
				unset( $this->metadata['Producer'] );
			}
		};
		if ( $remove_producer ) {
			$pdf->remove_producer_metadata();
		}
		$pdf->AddPage();
		$pdf->SetFont( 'Helvetica' );
		$pdf->Cell( 0, 10, 'Test' );
		$bytes = $pdf->Output( 'S' );

		$this->tmp_pdf_path = tempnam( sys_get_temp_dir(), 'hmpc-test-src-' ) . '.pdf';
		file_put_contents( $this->tmp_pdf_path, $bytes );

		return [ $this->tmp_pdf_path, $bytes ];
	}

	/**
	 * Build a source PDF whose Catalog points at a document-level XMP stream.
	 */
	private function make_real_pdf_with_xmp( string $xmp ) : array {
		$pdf = new class( $xmp ) extends \setasign\Fpdi\Fpdi {
			private string $document_xmp;
			private ?int $metadata_object_number = null;

			public function __construct( string $document_xmp ) {
				parent::__construct();
				$this->document_xmp = gzcompress( $document_xmp );
				unset( $this->metadata['Producer'] );
			}

			protected function _putresources() {
				parent::_putresources();
				$this->_newobj();
				$this->metadata_object_number = $this->n;
				$this->_put( '<</Type /Metadata /Subtype /XML /Filter /FlateDecode /Length ' . strlen( $this->document_xmp ) . '>>' );
				$this->_putstream( $this->document_xmp );
				$this->_put( 'endobj' );
			}

			protected function _putcatalog() {
				parent::_putcatalog();
				$this->_put( '/Metadata ' . $this->metadata_object_number . ' 0 R' );
			}
		};
		$pdf->AddPage();
		$pdf->SetFont( 'Helvetica' );
		$pdf->Cell( 0, 10, 'Document XMP test' );
		$bytes = $pdf->Output( 'S' );

		$this->tmp_pdf_path = tempnam( sys_get_temp_dir(), 'hmpc-test-xmp-' ) . '.pdf';
		file_put_contents( $this->tmp_pdf_path, $bytes );

		return [ $this->tmp_pdf_path, $bytes ];
	}

	public function test_passes_on_a_genuinely_clean_pdf() : void {
		[ $path, $bytes ] = $this->make_real_pdf();

		$result = Pdf\verify( $path, $bytes, $bytes );

		$this->assertTrue( $result['ok'] );
	}

	public function test_fails_on_empty_output() : void {
		$result = Pdf\verify( '/dev/null', 'anything', '' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'empty', $result['reason'] );
	}

	public function test_fails_when_missing_pdf_header() : void {
		$result = Pdf\verify( '/dev/null', 'NOT-A-PDF!!', 'NOT-A-PDF!!' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'header', $result['reason'] );
	}

	public function test_fails_on_length_mismatch() : void {
		$result = Pdf\verify( '/dev/null', '%PDF-1.4 aaaa', '%PDF-1.4 aaa' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'length', $result['reason'] );
	}

	public function test_fails_on_residual_creator_tool() : void {
		$data = '%PDF-1.4 <xmp:CreatorTool>Adobe Illustrator</xmp:CreatorTool>';

		$result = Pdf\verify( '/dev/null', $data, $data );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'CreatorTool', $result['reason'] );
	}

	public function test_fails_on_residual_non_empty_author_field() : void {
		$data = '%PDF-1.4 /Author (Jane Doe)';

		$result = Pdf\verify( '/dev/null', $data, $data );

		$this->assertFalse( $result['ok'] );
	}

	public function test_fails_on_residual_producer_field() : void {
		$data = '%PDF-1.4 /Producer (FPDF 1.9)';

		$result = Pdf\verify( '/dev/null', $data, $data );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Info dictionary', $result['reason'] );
	}

	public function test_rebuilder_removes_source_and_fpdf_producer_metadata() : void {
		[ $path ] = $this->make_real_pdf( false );

		$rebuilt = Pdf\rebuild_pdf( $path );

		$this->assertStringNotContainsString( '/Producer', $rebuilt['data'] );
	}

	public function test_rebuilder_preserves_filtered_document_level_xmp() : void {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF'
			. ' xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
			. ' xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/"'
			. ' xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
			. '<rdf:Description><dc:title>Public document title</dc:title>'
			. '<dc:rights>Copyright 2026</dc:rights><dc:language>en-GB</dc:language>'
			. '<dc:publisher>Example Publisher</dc:publisher>'
			. '<xmp:CreateDate>2026-07-22T12:34:56+03:00</xmp:CreateDate>'
			. '<xmpMM:VersionID>7</xmpMM:VersionID>'
			. '<dc:creator>Private Person</dc:creator><pdf:Producer>Secret Tool</pdf:Producer>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';
		[ $path ] = $this->make_real_pdf_with_xmp( $xmp );

		$rebuilt = Pdf\rebuild_pdf( $path );

		$this->assertStringContainsString( '/Metadata ', $rebuilt['data'] );
		$this->assertStringContainsString( 'Public document title', $rebuilt['data'] );
		$this->assertStringContainsString( 'Copyright 2026', $rebuilt['data'] );
		$this->assertStringContainsString( 'en-GB', $rebuilt['data'] );
		$this->assertStringContainsString( 'Example Publisher', $rebuilt['data'] );
		$this->assertStringContainsString( '2026-07-22T12:34:56+03:00', $rebuilt['data'] );
		$this->assertStringContainsString( '>7</xmpMM:VersionID>', $rebuilt['data'] );
		$this->assertStringNotContainsString( 'Private Person', $rebuilt['data'] );
		$this->assertStringNotContainsString( 'Secret Tool', $rebuilt['data'] );

		$final = Pdf\strip_metadata_streams( $rebuilt['data'] );
		$this->assertSame( 1, $final['stripped_count'] );
		$this->assertStringContainsString( 'Example Publisher', $final['data'] );
		$this->assertTrue( Pdf\verify( $path, $rebuilt['data'], $final['data'] )['ok'] );
	}

	public function test_allows_empty_author_field() : void {
		[ $path, $bytes ] = $this->make_real_pdf();
		$data              = $bytes . '/Author ()';

		$result = Pdf\verify( $path, $data, $data );

		// The empty-parens case must not trip the non-empty-field check —
		// whether it ultimately passes depends on page re-parsing, which is
		// exercised by test_passes_on_a_genuinely_clean_pdf(); here we only
		// assert the specific field check isn't the failure reason.
		if ( ! $result['ok'] ) {
			$this->assertStringNotContainsString( 'Author', $result['reason'] );
		} else {
			$this->assertTrue( $result['ok'] );
		}
	}

	public function test_fails_on_residual_illustrator_token() : void {
		$data = '%PDF-1.4 <illustrator:CreatorSubTool>AIRobin</illustrator:CreatorSubTool>';

		$result = Pdf\verify( '/dev/null', $data, $data );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'illustrator:', $result['reason'] );
	}
}
