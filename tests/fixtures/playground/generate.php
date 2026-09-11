<?php
/**
 * Regenerate the sample media imported by blueprint.json.
 *
 * Each file carries fake personal metadata (author, software, GPS-style
 * camera data) alongside the default allowed fields, so the Media Library
 * shows the plugin stripping one and keeping the other.
 *
 * Usage: php tests/fixtures/playground/generate.php (needs GD and `composer install`).
 */

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

$dir = __DIR__;

/**
 * Build a little-endian TIFF block holding ASCII EXIF tags.
 *
 * @param array<int, string> $tags Tag ID => value.
 */
function exif_block( array $tags ) : string {
	ksort( $tags );
	$count   = count( $tags );
	$offset  = 8 + 2 + ( 12 * $count ) + 4;
	$entries = '';
	$values  = '';
	foreach ( $tags as $tag => $value ) {
		$value   .= "\x00";
		$entries .= pack( 'vvVV', $tag, 2, strlen( $value ), $offset + strlen( $values ) );
		$values  .= $value;
	}
	return 'II' . pack( 'vV', 42, 8 ) . pack( 'v', $count ) . $entries . pack( 'V', 0 ) . $values;
}

/**
 * Draw a simple image so WordPress has real pixels to resize.
 */
function sample_image() : \GdImage {
	$image = imagecreatetruecolor( 1200, 800 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 30, 90, 160 ) );
	imagefilledellipse( $image, 600, 400, 600, 600, imagecolorallocate( $image, 250, 190, 40 ) );
	return $image;
}

// JPEG: EXIF + IPTC with personal data and allowed title/copyright.
$image = sample_image();
ob_start();
imagejpeg( $image, null, 85 );
$jpeg = ob_get_clean();

$exif = "Exif\x00\x00" . exif_block( [
	0x010E => 'Sample photo title',
	0x010F => 'Canon',
	0x0110 => 'Canon EOS R5',
	0x0131 => 'Secret Editor 26.9',
	0x013B => 'Jane Doe',
	0x8298 => 'Copyright 2026 Example Publisher',
] );
$dataset = static fn( int $id, string $value ) : string => "\x1C\x02" . chr( $id ) . pack( 'n', strlen( $value ) ) . $value;
$iptc    = $dataset( 5, 'Sample photo title' ) . $dataset( 80, 'Jane Doe' ) . $dataset( 116, 'Copyright 2026 Example Publisher' );
$app13   = "Photoshop 3.0\x00" . '8BIM' . pack( 'n', 0x0404 ) . "\x00\x00" . pack( 'N', strlen( $iptc ) ) . $iptc . ( strlen( $iptc ) % 2 ? "\x00" : '' );
$segment = static fn( int $marker, string $payload ) : string => "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;

file_put_contents( "$dir/sample-photo.jpg", substr( $jpeg, 0, 2 ) . $segment( 0xE1, $exif ) . $segment( 0xED, $app13 ) . substr( $jpeg, 2 ) );

// PNG: text chunks with an author and software, plus an allowed title.
ob_start();
imagepng( sample_image() );
$png   = ob_get_clean();
$chunk = static fn( string $type, string $data ) : string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
$text  = $chunk( 'tEXt', "Title\x00Sample graphic title" )
	. $chunk( 'tEXt', "Author\x00Jane Doe" )
	. $chunk( 'tEXt', "Software\x00Secret Editor 26.9" );

file_put_contents( "$dir/sample-graphic.png", substr( $png, 0, 33 ) . $text . substr( $png, 33 ) );

// PDF: /Info author and creator, plus an allowed title.
$pdf = new \setasign\Fpdi\Fpdi();
$pdf->SetTitle( 'Sample document title' );
$pdf->SetAuthor( 'Jane Doe' );
$pdf->SetCreator( 'Secret Word Processor 2026' );
$pdf->SetKeywords( 'internal, draft' );
$pdf->AddPage();
$pdf->SetFont( 'Helvetica', '', 16 );
$pdf->Cell( 0, 10, 'HM Media PII Cleaner sample document' );
$pdf->Output( 'F', "$dir/sample-document.pdf" );

echo "Sample media written to $dir\n";
