<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads stubs, the Composer autoloader, and the plugin files under test.
 * WordPress is NOT loaded — Brain\Monkey intercepts dynamic WP functions
 * per-test. Only deterministic helper functions are stubbed here.
 */

// WP class/function stubs — must load before autoloader so Brain\Monkey
// cannot redefine the pure helpers (__, esc_html, etc.) we stub here.
require_once __DIR__ . '/Stubs/WPStubs.php';

require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/TestCase.php';

$plugin_dir = dirname( __DIR__, 2 );

require_once $plugin_dir . '/inc/constants.php';
require_once $plugin_dir . '/inc/fields.php';
require_once $plugin_dir . '/inc/cli/helpers.php';
require_once $plugin_dir . '/inc/cli/class-results.php';
require_once $plugin_dir . '/inc/limits.php';
require_once $plugin_dir . '/inc/files.php';
require_once $plugin_dir . '/inc/xmp-filter.php';
require_once $plugin_dir . '/inc/status.php';
require_once $plugin_dir . '/inc/dispatcher.php';
require_once $plugin_dir . '/inc/render-guard.php';
require_once $plugin_dir . '/inc/images/bootstrap.php';
require_once $plugin_dir . '/inc/images/gif-sanitizer.php';
require_once $plugin_dir . '/inc/images/svg-sanitizer.php';
require_once $plugin_dir . '/inc/images/png-sanitizer.php';
require_once $plugin_dir . '/inc/images/webp-sanitizer.php';
require_once $plugin_dir . '/inc/images/jpeg-sanitizer.php';
require_once $plugin_dir . '/inc/pdf/rebuilder.php';
require_once $plugin_dir . '/inc/pdf/metadata-stripper.php';
require_once $plugin_dir . '/inc/pdf/verifier.php';
require_once $plugin_dir . '/inc/video/sanitizer.php';
require_once $plugin_dir . '/inc/video/bootstrap.php';
require_once $plugin_dir . '/inc/ooxml/sanitizer.php';
require_once $plugin_dir . '/inc/ooxml/bootstrap.php';
