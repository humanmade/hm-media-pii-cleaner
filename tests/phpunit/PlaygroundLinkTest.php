<?php

namespace HM\MediaPiiCleaner\Tests;

use HM\MediaPiiCleaner\Playground;

require_once dirname( __DIR__, 2 ) . '/bin/playground-blueprint.php';

/**
 * Guards the README "Try it in WordPress Playground" link, which carries
 * blueprint.json inline and so goes stale when the blueprint changes.
 */
class PlaygroundLinkTest extends TestCase {

	private const MAIN_ZIP_URL = 'https://github.com/humanmade/hm-media-pii-cleaner/releases/download/playground-main/hm-media-pii-cleaner.zip';

	public function test_readme_link_matches_blueprint() : void {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' );

		$this->assertStringContainsString(
			'[playground]: ' . Playground\playground_link( self::MAIN_ZIP_URL ) . "\n",
			$readme,
			'README Playground link is out of date. Regenerate it with: php bin/playground-blueprint.php --link ' . self::MAIN_ZIP_URL
		);
	}

	public function test_hosted_blueprint_installs_the_zip_instead_of_activating_a_mount() : void {
		$blueprint = Playground\hosted_blueprint( self::MAIN_ZIP_URL );
		$steps     = array_column( $blueprint['steps'], 'step' );

		$this->assertSame( 'installPlugin', $steps[0] );
		$this->assertSame( self::MAIN_ZIP_URL, $blueprint['steps'][0]['pluginData']['url'] );
		$this->assertSame( 'hm-media-pii-cleaner', $blueprint['steps'][0]['options']['targetFolderName'] );
		$this->assertNotContains( 'activatePlugin', $steps );
		$this->assertContains( 'wp-cli', $steps );
	}
}
