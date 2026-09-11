<?php
/**
 * Print the Playground blueprint for a hosted plugin zip.
 *
 * Takes blueprint.json (which expects the plugin to be mounted locally) and
 * replaces its activation step with one that installs the plugin from a zip
 * URL, so previews and the README link share the same setup steps.
 *
 * Usage:
 *   php bin/playground-blueprint.php <zip-url>          Blueprint JSON.
 *   php bin/playground-blueprint.php --link <zip-url>   playground.wordpress.net link.
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner\Playground;

const SLUG = 'hm-media-pii-cleaner';

/**
 * Build the hosted blueprint.
 *
 * @param string $zip_url Public URL of the built plugin zip.
 * @return array
 */
function hosted_blueprint( string $zip_url ) : array {
	$blueprint = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/blueprint.json' ), true, 512, JSON_THROW_ON_ERROR );

	$steps = array_filter( $blueprint['steps'], static fn( array $step ) : bool => 'activatePlugin' !== $step['step'] );
	array_unshift(
		$steps,
		[
			'step'       => 'installPlugin',
			'pluginData' => [
				'resource' => 'url',
				'url'      => $zip_url,
			],
			'options'    => [
				'activate'         => true,
				'targetFolderName' => SLUG,
			],
		]
	);
	$blueprint['steps'] = array_values( $steps );

	return $blueprint;
}

/**
 * Build a playground.wordpress.net link that carries the blueprint inline.
 * A data: URL avoids CORS, which a blueprint hosted on a GitHub release would hit.
 *
 * @param string $zip_url Public URL of the built plugin zip.
 * @return string
 */
function playground_link( string $zip_url ) : string {
	$json = json_encode( hosted_blueprint( $zip_url ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

	return 'https://playground.wordpress.net/?blueprint-url=data:application/json,' . rawurlencode( $json );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$args = array_slice( $argv, 1 );
	$link = '--link' === ( $args[0] ?? '' );
	$url  = $link ? ( $args[1] ?? '' ) : ( $args[0] ?? '' );

	if ( '' === $url ) {
		fwrite( STDERR, "Usage: php bin/playground-blueprint.php [--link] <zip-url>\n" );
		exit( 1 );
	}

	echo $link
		? playground_link( $url ) . "\n"
		: json_encode( hosted_blueprint( $url ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
}
