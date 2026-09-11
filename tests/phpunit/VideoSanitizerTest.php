<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\Video;

/**
 * Tests for Video\strip_video_metadata().
 */
class VideoSanitizerTest extends TestCase {

	private function box( string $type, string $body ) : string {
		return pack( 'N', 8 + strlen( $body ) ) . $type . $body;
	}

	/**
	 * A minimal, real-shaped ISOBMFF layout: ftyp, mdat (sample data), then
	 * moov (containing an iTunes-style udta/meta/ilst/©too atom carrying an
	 * encoder-tool string, plus a per-track udta/name atom) — the shape a
	 * HandBrake-exported file has by default.
	 */
	private function build_mp4( string $sample_data = 'fake-sample-bytes' ) : string {
		$ftyp = $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isomiso2mp41' );
		$mdat = $this->box( 'mdat', $sample_data );

		$too_data_box = $this->box( 'data', pack( 'N', 1 ) . pack( 'N', 0 ) . 'HandBrake 1.6.1 2023012300' );
		$ilst         = $this->box( 'ilst', $this->box( "\xA9too", $too_data_box ) );
		$hdlr         = $this->box( 'hdlr', str_repeat( "\x00", 21 ) . 'mdta' . "\x00" );
		// 'meta' is a full box (4-byte version+flags after the usual header).
		$meta         = pack( 'N', 8 + 4 + strlen( $hdlr ) + strlen( $ilst ) ) . 'meta' . "\x00\x00\x00\x00" . $hdlr . $ilst;
		$top_udta     = $this->box( 'udta', $meta );

		$track_udta = $this->box( 'udta', $this->box( 'name', 'Core Media Video' ) );
		$tkhd       = $this->box( 'tkhd', str_repeat( "\x00", 20 ) );
		$trak       = $this->box( 'trak', $tkhd . $track_udta );

		$mvhd = $this->box( 'mvhd', str_repeat( "\x00", 20 ) );
		$moov = $this->box( 'moov', $mvhd . $trak . $top_udta );

		return $ftyp . $mdat . $moov;
	}

	public function test_strips_top_level_and_per_track_udta() : void {
		$mp4    = $this->build_mp4();
		$result = Video\strip_video_metadata( $mp4 );

		$this->assertStringNotContainsString( 'HandBrake', $result['data'] );
		$this->assertStringNotContainsString( 'Core Media Video', $result['data'] );
		$this->assertGreaterThanOrEqual( 2, $result['stripped_boxes'] );
	}

	public function test_sample_data_is_byte_for_byte_unchanged() : void {
		$sample = 'fake-sample-bytes-' . str_repeat( 'X', 200 );
		$mp4    = $this->build_mp4( $sample );
		$result = Video\strip_video_metadata( $mp4 );

		$this->assertStringContainsString( $sample, $result['data'] );
	}

	public function test_output_reparses_as_valid_isobmff() : void {
		$mp4    = $this->build_mp4();
		$result = Video\strip_video_metadata( $mp4 );

		// Re-running strip_video_metadata() re-parses the box structure from
		// scratch — if the rebuilt sizes were wrong, this would throw.
		$again = Video\strip_video_metadata( $result['data'] );
		$this->assertSame( 0, $again['stripped_boxes'] );
	}

	public function test_idempotent_on_already_clean_output() : void {
		$first  = Video\strip_video_metadata( $this->build_mp4() );
		$second = Video\strip_video_metadata( $first['data'] );

		$this->assertSame( $first['data'], $second['data'] );
	}

	public function test_flags_fast_start_layout_instead_of_touching_it() : void {
		// moov before mdat: sample-offset tables would need patching. Must be
		// rejected, not silently mishandled.
		$ftyp = $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isom' );
		$mvhd = $this->box( 'mvhd', str_repeat( "\x00", 20 ) );
		$udta = $this->box( 'udta', $this->box( 'name', 'leaky-tool' ) );
		$moov = $this->box( 'moov', $mvhd . $udta );
		$mdat = $this->box( 'mdat', 'sample-bytes' );

		$this->expectException( \RuntimeException::class );
		Video\strip_video_metadata( $ftyp . $moov . $mdat );
	}

	public function test_rejects_fragmented_mp4() : void {
		$ftyp = $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isom' );
		$moof = $this->box( 'moof', str_repeat( "\x00", 8 ) );
		$mdat = $this->box( 'mdat', 'sample-bytes' );

		$this->expectException( \RuntimeException::class );
		Video\strip_video_metadata( $ftyp . $moof . $mdat );
	}

	public function test_rejects_missing_ftyp() : void {
		$mdat = $this->box( 'mdat', 'sample-bytes' );
		$moov = $this->box( 'moov', str_repeat( "\x00", 4 ) );

		$this->expectException( \RuntimeException::class );
		Video\strip_video_metadata( $mdat . $moov );
	}

	public function test_rejects_missing_mdat() : void {
		$ftyp = $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isom' );
		$moov = $this->box( 'moov', str_repeat( "\x00", 4 ) );

		$this->expectException( \RuntimeException::class );
		Video\strip_video_metadata( $ftyp . $moov );
	}

	public function test_leaves_video_without_any_udta_unchanged_except_recount() : void {
		$ftyp = $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isom' );
		$mdat = $this->box( 'mdat', 'sample-bytes' );
		$moov = $this->box( 'moov', $this->box( 'mvhd', str_repeat( "\x00", 20 ) ) );

		$result = Video\strip_video_metadata( $ftyp . $mdat . $moov );

		$this->assertSame( 0, $result['stripped_boxes'] );
		$this->assertSame( $ftyp . $mdat . $moov, $result['data'] );
	}

	/**
	 * A flagged video must never be quarantined: a video is often
	 * referenced by a hardcoded <video src> literal in saved page content,
	 * not resolved dynamically, so moving the file would 404 a live page. No
	 * quarantine-path function (wp_mkdir_p, wp_unique_filename, etc.) is
	 * mocked here — if sanitize_video_attachment() ever regresses to calling
	 * Files\quarantine_attachment() for a real, on-disk file, this test fails
	 * on the unmocked call rather than silently passing.
	 */
	public function test_flagged_video_is_not_quarantined() : void {
		Functions\when( 'get_post_meta' )->justReturn( [] );
		$ftyp = $this->box( 'ftyp', 'isom' . pack( 'N', 512 ) . 'isom' );
		$mvhd = $this->box( 'mvhd', str_repeat( "\x00", 20 ) );
		$udta = $this->box( 'udta', $this->box( 'name', 'leaky-tool' ) );
		$moov = $this->box( 'moov', $mvhd . $udta );
		$mdat = $this->box( 'mdat', 'sample-bytes' );

		$tmp_path = tempnam( sys_get_temp_dir(), 'hmpc-video-quarantine-test-' );
		file_put_contents( $tmp_path, $ftyp . $moov . $mdat ); // Fast-start layout: will be flagged.

		$statuses = [];
		Functions\when( 'get_attached_file' )->justReturn( $tmp_path );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $attachment_id, string $key, string $value ) use ( &$statuses ) : void {
				$statuses[ $key ] = $value;
			}
		);
		Functions\when( 'delete_post_meta' )->justReturn( true );

		try {
			Video\sanitize_video_attachment( 424, [] );
		} finally {
			$this->assertFileExists( $tmp_path, 'Flagged video file must not be moved/deleted.' );
			unlink( $tmp_path );
		}

		$this->assertSame( HM_MEDIA_PII_CLEANER_STATUS_FLAGGED, $statuses[ HM_MEDIA_PII_CLEANER_STATUS_META ] );
	}
}
