<?php
/**
 * Plugin Name: HM Media PII Cleaner
 * Plugin URI: https://github.com/humanmade/hm-media-pii-cleaner
 * Description: Strips hidden metadata (EXIF/IPTC/XMP, PDF Info/XMP) from publicly downloadable media at upload time, with verification and admin flagging for files that can't be safely sanitized.
 * Version: __VERSION__
 * Author: Human Made Limited
 * Author URI: https://humanmade.com
 * Text Domain: hm-media-pii-cleaner
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package HM\MediaPiiCleaner
 */

namespace HM\MediaPiiCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load bundled dependencies (FPDI) unless the site's Composer autoloader already provides them.
if ( ! class_exists( \setasign\Fpdi\Fpdi::class ) && is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/inc/constants.php';
require_once __DIR__ . '/inc/fields.php';
require_once __DIR__ . '/inc/limits.php';
require_once __DIR__ . '/inc/files.php';
require_once __DIR__ . '/inc/xmp-filter.php';
require_once __DIR__ . '/inc/status.php';
require_once __DIR__ . '/inc/dispatcher.php';
require_once __DIR__ . '/inc/images/bootstrap.php';
require_once __DIR__ . '/inc/images/gif-sanitizer.php';
require_once __DIR__ . '/inc/images/svg-sanitizer.php';
require_once __DIR__ . '/inc/images/png-sanitizer.php';
require_once __DIR__ . '/inc/images/webp-sanitizer.php';
require_once __DIR__ . '/inc/images/jpeg-sanitizer.php';
require_once __DIR__ . '/inc/pdf/rebuilder.php';
require_once __DIR__ . '/inc/pdf/metadata-stripper.php';
require_once __DIR__ . '/inc/pdf/verifier.php';
require_once __DIR__ . '/inc/pdf/bootstrap.php';
require_once __DIR__ . '/inc/video/sanitizer.php';
require_once __DIR__ . '/inc/video/bootstrap.php';
require_once __DIR__ . '/inc/ooxml/sanitizer.php';
require_once __DIR__ . '/inc/ooxml/bootstrap.php';
require_once __DIR__ . '/inc/render-guard.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/cli/class-command.php';
	require_once __DIR__ . '/inc/cli/class-results.php';
	require_once __DIR__ . '/inc/cli/helpers.php';
	add_action( 'cli_init', __NAMESPACE__ . '\\Cli\\bootstrap' );
}

add_action( 'init', __NAMESPACE__ . '\\Status\\bootstrap' );
add_action( 'init', __NAMESPACE__ . '\\Dispatcher\\bootstrap' );
add_action( 'init', __NAMESPACE__ . '\\Images\\bootstrap' );
add_action( 'init', __NAMESPACE__ . '\\Pdf\\bootstrap' );
add_action( 'init', __NAMESPACE__ . '\\RenderGuard\\bootstrap' );
