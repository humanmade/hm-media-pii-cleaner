<?php
/**
 * Stage 1 of PDF sanitization: rebuild the document page-by-page via FPDI,
 * dropping forms, JavaScript, attachments, bookmarks, and disallowed
 * document metadata. /Title and /CreationDate are read from the source, and
 * allowlisted Catalog XMP is filtered and written as a new Metadata object
 * before the output xref is generated. Confirmed by a manual
 * spike against real site PDFs: this fully cleans simple/Word-exported
 * PDFs. Adobe InDesign/Illustrator-exported PDFs carry per-object XMP
 * inside embedded artwork that survives this stage — see
 * metadata-stripper.php (Stage 2) for that.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Pdf;

use HM\MediaPiiCleaner\Fields;
use HM\MediaPiiCleaner\XmpFilter;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * The exact byte length + shape FPDF's own _putinfo() always generates for
 * CreationDate ('D:' + 14 digits + "+HH'MM'" or "-HH'MM'") — see fpdf.php's
 * `_putinfo()`. Only a source date in this same canonical form can be
 * swapped in after Output() without changing the file's byte length (and
 * therefore without invalidating every object offset the xref table records
 * after this point in the file).
 */
const CREATION_DATE_PATTERN = '/^D:\d{14}[+\-]\d{2}\'\d{2}\'$/';
const CREATION_DATE_LENGTH  = 23;

/**
 * Rebuild a PDF page-by-page, retaining only allowlisted document metadata.
 *
 * @param string $source_path Path to the source PDF.
 * @return array{data: string, page_count: int} Rebuilt PDF bytes and page count.
 * @throws \RuntimeException On any FPDI failure.
 */
function rebuild_pdf( string $source_path ) : array {
	if ( ! class_exists( \setasign\Fpdi\Fpdi::class ) ) {
		throw new \RuntimeException( 'setasign/fpdi is not installed.' );
	}

	$source_info = read_source_info( $source_path );

	try {
		$pdf = new class( $source_info['xmp'] ?? '' ) extends \setasign\Fpdi\Fpdi {
			/** @var string Filtered document-level XMP to write into the rebuilt PDF. */
			private string $document_xmp;

			/** @var int|null Indirect object number referenced by the Catalog's /Metadata entry. */
			private ?int $metadata_object_number = null;

			/**
			 * @param string $document_xmp Filtered document-level XMP packet.
			 */
			public function __construct( string $document_xmp ) {
				parent::__construct();
				$this->document_xmp = $document_xmp;
			}

			/** Remove FPDF's own software/version Producer metadata. */
			public function remove_producer_metadata() : void {
				unset( $this->metadata['Producer'] );
			}

			/** Write filtered document XMP as a new indirect stream before xref generation. */
			protected function _putresources() { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- FPDF extension point.
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

			/** Attach the newly written Metadata stream to the document Catalog. */
			protected function _putcatalog() { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- FPDF extension point.
				parent::_putcatalog();
				if ( null !== $this->metadata_object_number ) {
					$this->_put( '/Metadata ' . $this->metadata_object_number . ' 0 R' );
				}
			}
		};
		$pdf->remove_producer_metadata();
		$pdf->SetCreator( $source_info['info']['Creator'] ?? '', true );
		$pdf->SetAuthor( $source_info['info']['Author'] ?? '', true );
		$pdf->SetTitle( $source_info['info']['Title'] ?? '', true );
		$pdf->SetSubject( $source_info['info']['Subject'] ?? '', true );
		$pdf->SetKeywords( $source_info['info']['Keywords'] ?? '', true );

		$page_count = $pdf->setSourceFile( $source_path );

		for ( $i = 1; $i <= $page_count; $i++ ) {
			$template_id = $pdf->importPage( $i );
			$size        = $pdf->getTemplateSize( $template_id );
			$pdf->AddPage( $size['orientation'], [ $size['width'], $size['height'] ] );
			$pdf->useTemplate( $template_id );
		}

		$data = $pdf->Output( 'S' );

		if ( null !== $source_info['creation_date'] ) {
			$data = apply_source_creation_date( $data, $source_info['creation_date'] );
		}

		return [
			'data'       => $data,
			'page_count' => $page_count,
		];
	} catch ( \Throwable $e ) {
		throw new \RuntimeException( 'FPDI rebuild failed: ' . $e->getMessage(), 0, $e );
	}
}

/**
 * Read allowlisted source metadata from the /Info dictionary and Catalog XMP.
 *
 * Best-effort: any parse failure just means nothing is passed through — the
 * fields end up blank/auto-generated, never a hard failure of the rebuild.
 *
 * @param string $source_path Path to the source PDF.
 * @return array{info: array<string, string>, creation_date: ?string, xmp: string}
 *         `info` holds allowed text /Info values keyed by /Info key.
 */
function read_source_info( string $source_path ) : array {
	$result = [
		'info'          => [],
		'creation_date' => null,
		'xmp'           => '',
	];

	$allowed_keys = Fields\pdf_info_keys();

	try {
		$parser  = new PdfParser( StreamReader::createByFile( $source_path ) );
		$trailer = $parser->getCrossReference()->getTrailer();
		$info    = PdfType::resolve( PdfDictionary::get( $trailer, 'Info' ), $parser );

		if ( $info instanceof PdfDictionary ) {
			foreach ( array_diff( $allowed_keys, [ 'CreationDate' ] ) as $key ) {
				$value = PdfDictionary::get( $info, $key );
				if ( $value instanceof PdfString ) {
					$decoded = decode_pdf_string( PdfString::unescape( $value->value ) );
					if ( '' !== trim( $decoded ) ) {
						$result['info'][ $key ] = $decoded;
					}
				}
			}

			$creation_date = in_array( 'CreationDate', $allowed_keys, true ) ? PdfDictionary::get( $info, 'CreationDate' ) : null;
			if ( $creation_date instanceof PdfString ) {
				$raw = PdfString::unescape( $creation_date->value );
				if ( preg_match( CREATION_DATE_PATTERN, $raw ) ) {
					$result['creation_date'] = $raw;
				}
			}
		}

		$catalog = PdfType::resolve( PdfDictionary::get( $trailer, 'Root' ), $parser );
		if ( $catalog instanceof PdfDictionary ) {
			$metadata = PdfType::resolve( PdfDictionary::get( $catalog, 'Metadata' ), $parser );
			if ( $metadata instanceof PdfStream && is_xml_metadata_stream( $metadata ) ) {
				$filtered = XmpFilter\filter_xmp_packet( $metadata->getUnfilteredStream() );
				if ( XmpFilter\EMPTY_PACKET !== $filtered ) {
					$result['xmp'] = $filtered;
				}
			}
		}
	} catch ( \Throwable $e ) {
		// Best-effort — unavailable or malformed source metadata is dropped.
	}

	return $result;
}

/**
 * Validate that a Catalog metadata stream is the standard XML metadata type.
 *
 * @param PdfStream $stream Candidate metadata stream.
 * @return bool
 */
function is_xml_metadata_stream( PdfStream $stream ) : bool {
	$type    = PdfDictionary::get( $stream->value, 'Type' );
	$subtype = PdfDictionary::get( $stream->value, 'Subtype' );

	return $type instanceof PdfName
		&& 'Metadata' === $type->value
		&& $subtype instanceof PdfName
		&& 'XML' === $subtype->value;
}

/**
 * Decode a raw PDF text-string value to UTF-8, per the PDF spec's two text
 * string encodings: UTF-16BE with a leading BOM (used for anything outside
 * PDFDocEncoding's range, e.g. non-ASCII titles), or PDFDocEncoding
 * otherwise. PDFDocEncoding is approximated here as Latin-1, which is exact
 * for the printable ASCII subset that covers the overwhelming majority of
 * real values — an acceptable approximation for a display-only Title field.
 *
 * @param string $raw Unescaped raw bytes from a PdfString value.
 * @return string UTF-8 text.
 */
function decode_pdf_string( string $raw ) : string {
	if ( str_starts_with( $raw, "\xFE\xFF" ) ) {
		$decoded = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
		return false !== $decoded ? $decoded : '';
	}

	$decoded = mb_convert_encoding( $raw, 'UTF-8', 'ISO-8859-1' );
	return false !== $decoded ? $decoded : '';
}

/**
 * Swap the auto-generated CreationDate in a rendered PDF's /Info dictionary
 * for the source document's real one — but only when it's the exact same
 * byte length as what FPDF always generates, so no object offset after it
 * in the file (recorded in the already-finalized xref table) shifts.
 *
 * The Info object's exact byte offset is located structurally (via the
 * rendered file's own trailer/xref, the same technique metadata-stripper.php
 * uses for Metadata streams) rather than by a global text search — a global
 * `strpos()` for the literal string "/CreationDate (" is not safe against a
 * malicious source PDF whose (uncompressed) page content happens to contain
 * that same literal text ahead of the real Info object; confirmed by testing
 * that scenario directly, where a naive global search patched the page's
 * visible content instead of (or as well as) the real Info dictionary.
 *
 * @param string $data          Rendered PDF bytes (Output('S') result).
 * @param string $source_date   Source /CreationDate value, already validated
 *                               against CREATION_DATE_PATTERN.
 * @return string
 */
function apply_source_creation_date( string $data, string $source_date ) : string {
	if ( strlen( $source_date ) !== CREATION_DATE_LENGTH ) {
		return $data; // Defensive — pattern already enforces this length.
	}

	try {
		$parser        = new PdfParser( StreamReader::createByString( $data ) );
		$xref          = $parser->getCrossReference();
		$info_ref      = PdfDictionary::get( $xref->getTrailer(), 'Info' );
		$info_indirect = PdfType::resolve( $info_ref, $parser, true );

		if ( ! ( $info_indirect instanceof PdfIndirectObject ) ) {
			return $data;
		}

		$object_offset = $xref->getOffsetFor( $info_indirect->objectNumber ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native FPDI object property.
		if ( false === $object_offset ) {
			return $data;
		}

		$object_end = strpos( $data, 'endobj', $object_offset );
		$search_end = false !== $object_end ? $object_end : strlen( $data );
	} catch ( \Throwable $e ) {
		return $data; // Best-effort — leave the auto-generated date alone.
	}

	$pos = strpos( $data, '/CreationDate (', $object_offset );
	if ( false === $pos || $pos >= $search_end ) {
		return $data; // Not found within the Info object's own byte range.
	}

	$value_start = $pos + strlen( '/CreationDate (' );
	$value_end   = strpos( $data, ')', $value_start );
	if ( false === $value_end || $value_end >= $search_end || ( $value_end - $value_start ) !== CREATION_DATE_LENGTH ) {
		return $data; // Not the fixed-format value we expect — leave it alone.
	}

	return substr_replace( $data, $source_date, $value_start, CREATION_DATE_LENGTH );
}

/**
 * Count the pages in a PDF without rebuilding it — used by the verifier to
 * compare the source's page count against the rebuilt output's.
 *
 * @param string $path Path to a PDF file.
 * @return int
 * @throws \RuntimeException On any FPDI failure.
 */
function count_pages( string $path ) : int {
	if ( ! class_exists( \setasign\Fpdi\Fpdi::class ) ) {
		throw new \RuntimeException( 'setasign/fpdi is not installed.' );
	}

	try {
		$pdf = new \setasign\Fpdi\Fpdi();
		return $pdf->setSourceFile( $path );
	} catch ( \Throwable $e ) {
		throw new \RuntimeException( 'Unable to count pages: ' . $e->getMessage(), 0, $e );
	}
}
