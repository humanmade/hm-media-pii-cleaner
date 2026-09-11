<?php

namespace HM\MediaPiiCleaner\Tests;

use Brain\Monkey\Functions;
use HM\MediaPiiCleaner\Dispatcher;
use HM\MediaPiiCleaner\Images;

/**
 * Tests upload-time ordering around EWWW Image Optimizer.
 */
class HookSequencingTest extends TestCase {

	public function test_dispatcher_sanitizes_before_and_after_ewww_priorities() : void {
		$GLOBALS['hm_test_registered_hooks'] = [];

		Dispatcher\bootstrap();

		$this->assertContains(
			[ 'filter', 'wp_generate_attachment_metadata', 'HM\\MediaPiiCleaner\\Dispatcher\\sanitize_images_before_ewww', 7, 2 ],
			$GLOBALS['hm_test_registered_hooks']
		);
		$this->assertContains(
			[ 'filter', 'wp_generate_attachment_metadata', 'HM\\MediaPiiCleaner\\Dispatcher\\sanitize_and_verify', 20, 2 ],
			$GLOBALS['hm_test_registered_hooks']
		);
	}

	public function test_ewww_blanket_metadata_removal_is_forced_off() : void {
		$GLOBALS['hm_test_registered_hooks'] = [];
		Functions\when( 'get_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'update_option' )
			->once()
			->with( 'ewww_image_optimizer_metadata_remove', false );

		Images\enforce_ewww_metadata_policy();

		$this->assertContains(
			[ 'filter', 'pre_option_ewww_image_optimizer_metadata_remove', 'HM\\MediaPiiCleaner\\Images\\return_zero', 10, 1 ],
			$GLOBALS['hm_test_registered_hooks']
		);
		$this->assertContains(
			[ 'filter', 'pre_site_option_ewww_image_optimizer_metadata_remove', 'HM\\MediaPiiCleaner\\Images\\return_zero', 10, 1 ],
			$GLOBALS['hm_test_registered_hooks']
		);
		$this->assertContains(
			[ 'action', 'admin_notices', 'HM\\MediaPiiCleaner\\Images\\render_ewww_policy_notice', 10, 1 ],
			$GLOBALS['hm_test_registered_hooks']
		);
		$this->assertSame( 0, Images\return_zero() );
	}
}
