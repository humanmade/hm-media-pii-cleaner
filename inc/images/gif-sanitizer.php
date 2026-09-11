<?php
/**
 * Byte-level GIF metadata stripper.
 *
 * Deliberately does NOT decode/re-encode the image (e.g. via GD's imagegif())
 * — that flattens animated GIFs to a single frame. Instead this walks the
 * GIF89a block structure and removes only Comment Extensions and any
 * Application Extension that isn't NETSCAPE2.0 (the looping-animation
 * control block), leaving all image data and frame timing byte-for-byte
 * untouched.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Images;

const GIF_EXT_INTRODUCER       = "\x21";
const GIF_EXT_GRAPHIC_CONTROL  = "\xF9";
const GIF_EXT_PLAIN_TEXT       = "\x01";
const GIF_EXT_COMMENT          = "\xFE";
const GIF_EXT_APPLICATION      = "\xFF";
const GIF_IMAGE_DESCRIPTOR     = "\x2C";
const GIF_TRAILER              = "\x3B";
const GIF_KEEP_APP_IDENTIFIER  = 'NETSCAPE2.0';

/**
 * Strip Comment Extensions and non-NETSCAPE Application Extensions from a
 * GIF's raw bytes.
 *
 * @param string $data Raw GIF file contents.
 * @return array{data: string, stripped_blocks: int, frame_count: int}
 * @throws \RuntimeException If the block structure can't be safely walked.
 */
function strip_gif_metadata( string $data ) : array {
	$len = strlen( $data );

	if ( $len < 13 || ( 'GIF87a' !== substr( $data, 0, 6 ) && 'GIF89a' !== substr( $data, 0, 6 ) ) ) {
		throw new \RuntimeException( 'Not a GIF file (bad header).' );
	}

	$out = substr( $data, 0, 6 ); // Header.
	$pos = 6;

	// Logical Screen Descriptor: width(2) height(2) packed(1) bgcolor(1) aspect(1).
	if ( $len < $pos + 7 ) {
		throw new \RuntimeException( 'Truncated Logical Screen Descriptor.' );
	}
	$packed = ord( $data[ $pos + 4 ] );
	$out   .= substr( $data, $pos, 7 );
	$pos   += 7;

	if ( $packed & 0x80 ) {
		$gct_size = 3 * ( 2 ** ( ( $packed & 0x07 ) + 1 ) );
		if ( $len < $pos + $gct_size ) {
			throw new \RuntimeException( 'Truncated Global Color Table.' );
		}
		$out .= substr( $data, $pos, $gct_size );
		$pos += $gct_size;
	}

	$stripped    = 0;
	$frame_count = 0;

	while ( $pos < $len ) {
		$introducer = $data[ $pos ];

		if ( GIF_TRAILER === $introducer ) {
			$out .= $introducer;
			++$pos;
			break;
		}

		if ( GIF_EXT_INTRODUCER === $introducer ) {
			if ( $pos + 1 >= $len ) {
				throw new \RuntimeException( 'Truncated extension introducer.' );
			}
			$label = $data[ $pos + 1 ];

			if ( GIF_EXT_COMMENT === $label ) {
				$end = skip_sub_blocks( $data, $pos + 2, $len );
				++$stripped;
				$pos = $end; // Do not copy — this block is dropped entirely.
				continue;
			}

			if ( GIF_EXT_APPLICATION === $label ) {
				if ( $pos + 3 >= $len ) {
					throw new \RuntimeException( 'Truncated Application Extension.' );
				}
				$block_size = ord( $data[ $pos + 2 ] );
				$identifier = substr( $data, $pos + 3, $block_size );
				$end        = skip_sub_blocks( $data, $pos + 3 + $block_size, $len );

				if ( GIF_KEEP_APP_IDENTIFIER === $identifier ) {
					$out .= substr( $data, $pos, $end - $pos );
				} else {
					++$stripped;
				}
				$pos = $end;
				continue;
			}

			if ( GIF_EXT_GRAPHIC_CONTROL === $label || GIF_EXT_PLAIN_TEXT === $label ) {
				$end  = skip_sub_blocks_after_fixed_block( $data, $pos + 2, $len );
				$out .= substr( $data, $pos, $end - $pos );
				$pos  = $end;
				continue;
			}

			// Unknown extension labels can carry arbitrary private metadata.
			$pos = skip_sub_blocks( $data, $pos + 2, $len );
			++$stripped;
			continue;
		}

		if ( GIF_IMAGE_DESCRIPTOR === $introducer ) {
			if ( $pos + 10 > $len ) {
				throw new \RuntimeException( 'Truncated Image Descriptor.' );
			}
			$img_packed = ord( $data[ $pos + 9 ] );
			$desc_end   = $pos + 10;

			if ( $img_packed & 0x80 ) {
				$lct_size = 3 * ( 2 ** ( ( $img_packed & 0x07 ) + 1 ) );
				if ( $len < $desc_end + $lct_size ) {
					throw new \RuntimeException( 'Truncated Local Color Table.' );
				}
				$desc_end += $lct_size;
			}

			if ( $desc_end >= $len ) {
				throw new \RuntimeException( 'Truncated image data (missing LZW min code size).' );
			}
			$data_end = skip_sub_blocks( $data, $desc_end + 1, $len );

			$out .= substr( $data, $pos, $data_end - $pos );
			$pos  = $data_end;
			++$frame_count;
			continue;
		}

		throw new \RuntimeException( sprintf( 'Unrecognised block introducer 0x%02X at offset %d.', ord( $introducer ), $pos ) );
	}

	return [
		'data'            => $out,
		'stripped_blocks' => $stripped,
		'frame_count'     => $frame_count,
	];
}

/**
 * Skip a series of size-prefixed sub-blocks, terminated by a zero-size block.
 *
 * @param string $data Full file bytes.
 * @param int    $pos  Offset of the first sub-block's size byte.
 * @param int    $len  Total length of $data.
 * @return int Offset immediately after the terminating zero-size byte.
 * @throws \RuntimeException If truncated before a terminator is found.
 */
function skip_sub_blocks( string $data, int $pos, int $len ) : int {
	while ( true ) {
		if ( $pos >= $len ) {
			throw new \RuntimeException( 'Truncated sub-block sequence (no terminator).' );
		}
		$size = ord( $data[ $pos ] );
		++$pos;
		if ( 0 === $size ) {
			return $pos;
		}
		$pos += $size;
	}
}

/**
 * Skip a single fixed-size block (declared by its own leading size byte)
 * followed by a sub-block sequence — the shape used by Graphic Control and
 * Plain Text Extensions.
 *
 * @param string $data Full file bytes.
 * @param int    $pos  Offset of the fixed block's size byte.
 * @param int    $len  Total length of $data.
 * @return int Offset immediately after the whole extension.
 * @throws \RuntimeException If truncated.
 */
function skip_sub_blocks_after_fixed_block( string $data, int $pos, int $len ) : int {
	if ( $pos >= $len ) {
		throw new \RuntimeException( 'Truncated extension (missing block size).' );
	}
	$block_size = ord( $data[ $pos ] );
	$pos       += 1 + $block_size;

	return skip_sub_blocks( $data, $pos, $len );
}
