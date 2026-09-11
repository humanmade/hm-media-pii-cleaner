<?php
/**
 * Minimal stubs for WordPress classes and pure functions.
 *
 * Dynamic WP functions (get_option, wp_get_environment_type, etc.) are NOT
 * stubbed here — Brain\Monkey intercepts those per-test.
 *
 * These stubs must be loaded before the plugin files so that class existence
 * checks (instanceof) work correctly.
 */

// -------------------------------------------------------------------------
// WordPress classes
// -------------------------------------------------------------------------

if ( ! class_exists( \WP_Error::class ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string|int $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = (string) $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() : string {
			return $this->code;
		}

		public function get_error_message( string $code = '' ) : string {
			return $this->message;
		}

		public function get_error_data( string $code = '' ) : mixed {
			return $this->data;
		}
	}
}

// -------------------------------------------------------------------------
// WordPress pure helper functions (no mocking needed — deterministic).
// -------------------------------------------------------------------------

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ) : string {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( string $text, string $domain = 'default' ) : void {
		echo $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ) : string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ) : string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url, ?array $protocols = null ) : string {
		return $url;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ) : string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ) : int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ) : string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ) : bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() : string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) : void {
		$GLOBALS['hm_test_registered_hooks'][] = [ 'action', $hook, $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) : void {
		$GLOBALS['hm_test_registered_hooks'][] = [ 'filter', $hook, $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ) : string {
		return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '' ) : string {
		return tempnam( sys_get_temp_dir(), 'wp-test-' );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ) : void {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}

// EWWW presence marker used by media-sanitizer policy tests.
if ( ! function_exists( 'ewww_image_optimizer_get_option' ) ) {
	function ewww_image_optimizer_get_option( string $option_name, mixed $default_value = false, bool $single = false ) : mixed {
		return $default_value;
	}
}

