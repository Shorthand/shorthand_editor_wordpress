<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'THESHED_PLUGIN_FILE' ) ) {
	define( 'THESHED_PLUGIN_FILE', '/var/www/html/wp-content/plugins/the-shorthand-editor/the-shorthand-editor.php' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/**
		 * @var array<string, array<int, string>>
		 */
		public $errors = array();

		/**
		 * @param mixed $data
		 */
		public function __construct( string $code = '', string $message = '', $data = null ) {
			if ( $code !== '' ) {
				$this->errors[ $code ] = array( $message );
			}
		}

		public function get_error_code(): string {
			foreach ( $this->errors as $code => $_messages ) {
				return (string) $code;
			}
			return '';
		}

		public function get_error_message(): string {
			$code = $this->get_error_code();

			if ( $code === '' || empty( $this->errors[ $code ] ) ) {
				return '';
			}

			return $this->errors[ $code ][0];
		}

		/**
		 * @param mixed $data
		 */
		public function add( string $code, string $message, $data = null ): void {
			if ( ! isset( $this->errors[ $code ] ) ) {
				$this->errors[ $code ] = array();
			}

			$this->errors[ $code ][] = $message;
		}
	}
}

/**
 * Reset the small slice of WordPress state used by the unit tests.
 */
function tests_wp_reset_state(): void {
	$GLOBALS['tests_wp_state'] = array(
		'options'              => array(),
		'transients'           => array(),
		'transient_ttls'       => array(),
		'site_transients'      => array(),
		'settings_errors'      => array(),
		'remote_requests'      => array(),
		'remote_get_response'  => array(
			'response' => array(
				'code' => 200,
			),
			'body'     => '',
		),
		'enqueued_scripts'     => array(),
		'registered_scripts'   => array(),
		'inline_scripts'       => array(),
		'enqueued_styles'      => array(),
		'registered_styles'    => array(),
		'inline_styles'        => array(),
		'environment_type'     => 'production',
		'posts_queries'        => array(),
		'posts_query_result'   => array(),
		'inserted_posts'       => array(),
		'insert_post_result'   => 0,
		'stub_posts'           => array(),
		'updated_post_meta'    => array(),
	);
	$GLOBALS['wp_version']   = '6.0';
}

tests_wp_reset_state();

/**
 * @param mixed $response
 */
function tests_wp_set_remote_response( $response ): void {
	$GLOBALS['tests_wp_state']['remote_get_response'] = $response;
}

/**
 * @param mixed $value
 */
function tests_wp_set_option( string $name, $value ): void {
	$GLOBALS['tests_wp_state']['options'][ $name ] = $value;
}

/**
 * @param mixed $value
 */
function tests_wp_set_transient( string $name, $value, int $ttl = 0 ): void {
	$GLOBALS['tests_wp_state']['transients'][ $name ]     = $value;
	$GLOBALS['tests_wp_state']['transient_ttls'][ $name ] = $ttl;
}

/**
 * @return mixed
 */
function tests_wp_get_transient( string $name ) {
	return $GLOBALS['tests_wp_state']['transients'][ $name ] ?? false;
}

function tests_wp_get_transient_ttl( string $name ): ?int {
	return $GLOBALS['tests_wp_state']['transient_ttls'][ $name ] ?? null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_remote_requests(): array {
	return $GLOBALS['tests_wp_state']['remote_requests'];
}

/**
 * @return array<int, array<string, string>>
 */
function tests_wp_settings_errors(): array {
	return $GLOBALS['tests_wp_state']['settings_errors'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_enqueued_scripts(): array {
	return $GLOBALS['tests_wp_state']['enqueued_scripts'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_registered_scripts(): array {
	return $GLOBALS['tests_wp_state']['registered_scripts'];
}

/**
 * @return array<int, array<string, string>>
 */
function tests_wp_inline_scripts(): array {
	return $GLOBALS['tests_wp_state']['inline_scripts'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_enqueued_styles(): array {
	return $GLOBALS['tests_wp_state']['enqueued_styles'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_registered_styles(): array {
	return $GLOBALS['tests_wp_state']['registered_styles'];
}

/**
 * @return array<int, array<string, string>>
 */
function tests_wp_inline_styles(): array {
	return $GLOBALS['tests_wp_state']['inline_styles'];
}

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {}

function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {}

function remove_filter( string $hook_name, $callback, int $priority = 10 ): void {}

function register_activation_hook( string $file, $callback ): void {}

function register_deactivation_hook( string $file, $callback ): void {}

function register_setting( string $option_group, string $option_name, array $args = array() ): bool {
	return true;
}

function is_admin(): bool {
	return false;
}

function flush_rewrite_rules(): void {}

function wp_allowed_protocols(): array {
	return array( 'http', 'https' );
}

function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function plugin_dir_path( string $file ): string {
	return trailingslashit( dirname( $file ) );
}

function plugins_url( string $path = '', string $plugin = '' ): string {
	$base_url = 'https://example.test/wp-content/plugins/' . basename( dirname( $plugin ) );
	if ( $path === '' ) {
		return $base_url;
	}

	return $base_url . '/' . ltrim( $path, '/' );
}

function wp_get_environment_type(): string {
	return $GLOBALS['tests_wp_state']['environment_type'];
}

function delete_transient( string $transient ): bool {
	unset( $GLOBALS['tests_wp_state']['transients'][ $transient ] );
	unset( $GLOBALS['tests_wp_state']['transient_ttls'][ $transient ] );
	return true;
}

/**
 * @return mixed
 */
function get_transient( string $transient ) {
	return $GLOBALS['tests_wp_state']['transients'][ $transient ] ?? false;
}

/**
 * @param mixed $value
 */
function set_transient( string $transient, $value, int $expiration ): bool {
	$GLOBALS['tests_wp_state']['transients'][ $transient ]     = $value;
	$GLOBALS['tests_wp_state']['transient_ttls'][ $transient ] = $expiration;
	return true;
}

/**
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	$GLOBALS['tests_wp_state']['remote_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	return $GLOBALS['tests_wp_state']['remote_get_response'];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_request( string $url, array $args = array() ) {
	$GLOBALS['tests_wp_state']['remote_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	return $GLOBALS['tests_wp_state']['remote_get_response'];
}

/**
 * @param mixed $thing
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * @param array<string, mixed> $response
 */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

/**
 * @param array<string, mixed> $response
 */
function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

/**
 * @param mixed $message
 */
function add_settings_error( string $setting, string $code, $message ): void {
	$GLOBALS['tests_wp_state']['settings_errors'][] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => (string) $message,
	);
}

/**
 * @return mixed
 */
function get_option( string $option, $default = false ) {
	return $GLOBALS['tests_wp_state']['options'][ $option ] ?? $default;
}

/**
 * @param mixed $value
 */
function update_option( string $option, $value ): bool {
	$GLOBALS['tests_wp_state']['options'][ $option ] = $value;
	return true;
}

/**
 * @param array<int, string> $deps
 * @param mixed $ver
 * @param mixed $args
 */
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, $args = false ): void {
	$GLOBALS['tests_wp_state']['enqueued_scripts'][] = array(
		'handle' => $handle,
		'src'    => $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'args'   => $args,
	);
}

/**
 * @param array<int, string> $deps
 * @param mixed $ver
 * @param mixed $args
 */
function wp_register_script( string $handle, $src = '', array $deps = array(), $ver = false, $args = false ): void {
	$GLOBALS['tests_wp_state']['registered_scripts'][] = array(
		'handle' => $handle,
		'src'    => false === $src ? '' : (string) $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'args'   => $args,
	);
}

function wp_add_inline_script( string $handle, string $data ): bool {
	$GLOBALS['tests_wp_state']['inline_scripts'][] = array(
		'handle' => $handle,
		'data'   => $data,
	);
	return true;
}

/**
 * @param array<int, string> $deps
 * @param mixed $ver
 */
function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void {
	$GLOBALS['tests_wp_state']['enqueued_styles'][] = array(
		'handle' => $handle,
		'src'    => $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'media'  => $media,
	);
}

/**
 * @param array<int, string> $deps
 * @param mixed $ver
 */
function wp_register_style( string $handle, $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void {
	$GLOBALS['tests_wp_state']['registered_styles'][] = array(
		'handle' => $handle,
		'src'    => false === $src ? '' : (string) $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'media'  => $media,
	);
}

function wp_add_inline_style( string $handle, string $data ): bool {
	$GLOBALS['tests_wp_state']['inline_styles'][] = array(
		'handle' => $handle,
		'data'   => $data,
	);
	return true;
}

/**
 * @param mixed $value
 */
function wp_json_encode( $value ): string {
	return (string) json_encode( $value );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function wp_kses_no_null( string $value ): string {
	return str_replace( "\0", '', $value );
}

function get_bloginfo( string $show = '' ): string {
	return 'Example Site';
}

function get_site_url(): string {
	return 'https://example.test';
}

/**
 * @return mixed
 */
function get_site_transient( string $transient ) {
	return $GLOBALS['tests_wp_state']['site_transients'][ $transient ] ?? false;
}

/**
 * @param mixed $value
 */
function set_site_transient( string $transient, $value, int $expiration = 0 ): bool {
	$GLOBALS['tests_wp_state']['site_transients'][ $transient ] = $value;
	return true;
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function self_admin_url( string $path = '' ): string {
	return admin_url( $path );
}

function get_rest_url(): string {
	return 'https://example.test/wp-json';
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

/**
 * Minimal stand-in for WordPress's `add_query_arg()`, supporting only the
 * `add_query_arg( array $args, string $url )` call form used in this
 * codebase (not the 3-arg `key, value, url` form).
 *
 * @param array<string, mixed> $args
 */
function add_query_arg( array $args, string $url ): string {
	if ( empty( $args ) ) {
		return $url;
	}

	$pairs = array();
	foreach ( $args as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}

	$separator = false === strpos( $url, '?' ) ? '?' : '&';

	return $url . $separator . implode( '&', $pairs );
}

/**
 * @param mixed $value
 */
function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
}

/**
 * @param mixed $value
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_textarea_field( string $value ): string {
	return trim( wp_strip_all_tags( $value ) );
}

function wp_strip_all_tags( string $value ): string {
	return trim( strip_tags( $value ) );
}

/**
 * @return array<int, mixed>
 */
function tests_wp_posts_queries(): array {
	return $GLOBALS['tests_wp_state']['posts_queries'];
}

/**
 * @param array<int, mixed> $result
 */
function tests_wp_set_posts_query_result( array $result ): void {
	$GLOBALS['tests_wp_state']['posts_query_result'] = $result;
}

/**
 * @param array<string, mixed> $args
 * @return array<int, mixed>
 */
function get_posts( array $args = array() ): array {
	$GLOBALS['tests_wp_state']['posts_queries'][] = $args;
	return $GLOBALS['tests_wp_state']['posts_query_result'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_inserted_posts(): array {
	return $GLOBALS['tests_wp_state']['inserted_posts'];
}

/**
 * @param mixed $result
 */
function tests_wp_set_insert_post_result( $result ): void {
	$GLOBALS['tests_wp_state']['insert_post_result'] = $result;
}

/**
 * @param array<string, mixed> $postarr
 * @param mixed                $wp_error
 * @return mixed
 */
function wp_insert_post( array $postarr, $wp_error = false ) {
	$GLOBALS['tests_wp_state']['inserted_posts'][] = $postarr;
	return $GLOBALS['tests_wp_state']['insert_post_result'];
}

/**
 * @param mixed $post
 */
function tests_wp_set_post( int $post_id, $post ): void {
	$GLOBALS['tests_wp_state']['stub_posts'][ $post_id ] = $post;
}

/**
 * @return mixed
 */
function get_post( int $post_id ) {
	return $GLOBALS['tests_wp_state']['stub_posts'][ $post_id ] ?? null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function tests_wp_updated_post_meta(): array {
	return $GLOBALS['tests_wp_state']['updated_post_meta'];
}

/**
 * @param mixed $meta_value
 */
function update_post_meta( int $post_id, string $meta_key, $meta_value ): bool {
	$GLOBALS['tests_wp_state']['updated_post_meta'][] = array(
		'post_id'    => $post_id,
		'meta_key'   => $meta_key,
		'meta_value' => $meta_value,
	);
	return true;
}

require_once __DIR__ . '/../vendor/autoload.php';
spl_autoload_register(
	function ( string $class ): void {
		$prefix = 'Shorthand\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = __DIR__ . '/../src/lib/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
require_once __DIR__ . '/WordPressTestCase.php';
