<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Core\Loader;

class Options {

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	public function __construct( Version $version ) {
		$this->version = $version;
	}

	public function init() {
		$loader = new Loader();
		$loader->add_action( 'init', $this, 'register', 10, 0 );
		$loader->register();
	}

	public function register() {
		register_setting(
			'theshed-general-options-group',
			'shorthand_v2_token',
			array(
				'type'              => 'string',
				'label'             => __( 'Shorthand API token', 'the-shorthand-editor' ),
				'description'       => __( 'A Shorthand API token, associated with the connected workspace', 'the-shorthand-editor' ),
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'theshed-general-options-group',
			'shorthand_permalink',
			array(
				'type'              => 'string',
				'label'             => __( 'Permalink structure', 'the-shorthand-editor' ),
				'description'       => __( 'Set the permalink structure for published Shorthand story posts', 'the-shorthand-editor' ),
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'story',
			)
		);

		register_setting(
			'theshed-general-options-group',
			'shorthand_regex_list',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_regex_list' ),
				'default'           => '',
			)
		);

		register_setting(
			'theshed-general-options-group',
			'shorthand_css',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_no_null',
				'default'           => '',
			)
		);

		register_setting(
			'theshed-general-options-group',
			'shorthand_disable_cron',
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return (bool) $value;
				},
				'default'           => false,
			)
		);

		/* Internal settings, used to persist  token information */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_token_info',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_v2_token_info' ),
				'default'           => null,
			)
		);

		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_signing_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_verifying_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_next_signing_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_next_verifying_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		$loader = new Loader();

		$loader->add_action( 'update_option_shorthand_permalink', $this, 'handle_permalink_updated', 10, 3 );
		$loader->add_action( 'add_option_shorthand_permalink', $this, 'handle_permalink_added', 10, 2 );

		$loader->register();
	}

	public function get_default_css(): string {
		FileSystem::init();
		global $wp_filesystem;

		$default_css_path = $this->version->get_plugin_path( 'assets/css/options-css.default.css' );
		$default_css      = $wp_filesystem->get_contents( $default_css_path );
		if ( $default_css === false ) {
			return '';
		}
		return $default_css;
	}

	public function handle_permalink_added( $option, $value ): void {
		flush_rewrite_rules();
	}

	public function handle_permalink_updated( $option, $old_value, $value ): void {
		flush_rewrite_rules();
	}

	public function sanitize_v2_token_info( $token_info ) {
		$result = array(
			'team_id'         => sanitize_text_field( $token_info['team_id'] ),
			'organisation_id' => sanitize_text_field( $token_info['organisation_id'] ),
			'workspace'       => sanitize_text_field( $token_info['workspace'] ),
			'name'            => sanitize_text_field( $token_info['name'] ),
			'logo'            => sanitize_text_field( $token_info['logo'] ),
			'token_type'      => sanitize_text_field( $token_info['token_type'] ),
		);
		return $result;
	}

	public function sanitize_regex_list( $regex_list ) {
		$regex_list = trim( $regex_list );

		if ( ! $regex_list ) {
			return $regex_list;
		}

		$object = json_decode( $regex_list );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$msg = json_last_error_msg();
			add_settings_error( 'shorthand_regex_list', 'INVALID_REGEX_LIST', 'The post processing rules were invalid and could not be saved.' );
			return;
		}

		if ( isset( $object->head ) && ! is_array( $object->head ) ) {
			add_settings_error( 'shorthand_regex_list', 'INVALID_REGEX_LIST', 'The post processing `head` rule should be an array.' );
			return;
		}

		if ( isset( $object->body ) && ! is_array( $object->body ) ) {
			add_settings_error( 'shorthand_regex_list', 'INVALID_REGEX_LIST', 'The post processing `body` rule should be an array.' );
			return;
		}

		if ( ( isset( $object->head ) && ! array_reduce( $object->head, array( self::class, 'check_regex_query' ), true ) ) ) {
			add_settings_error( 'shorthand_regex_list', 'INVALID_REGEX_LIST', 'The post processing `head` rules should be an array of `query` and `replace` strings.' );
			return;
		}

		if ( isset( $object->body ) && ! array_reduce( $object->body, array( self::class, 'check_regex_query' ), true ) ) {
			add_settings_error( 'shorthand_regex_list', 'INVALID_REGEX_LIST', 'The post processing `body` rules should be an array of `query` and `replace` strings.' );
			return;
		}

		return $regex_list;
	}

	public static function check_regex_query( bool $carry, object $query ): bool {
		if ( ! $carry ) {
			return false;
		}

		if ( ! is_object( $query ) ) {
			return false;
		}
		if (
			! isset( $query->query ) ||
			! is_string( $query->query ) ||
			! isset( $query->replace ) ||
			! is_string( $query->replace )
		) {
			return false;
		}

		return ! ( @preg_match( $query->query, null ) === false );
	}

	private function get_token_info_block(): ?array {
		return get_option( 'shorthand_v2_token_info' );
	}

	public function get_token_org_id() {
		$token_info = $this->get_token_info_block();
		if ( $token_info == false ) {
			return '';
		}
		return isset( $token_info['organisation_id'] ) ? ( $token_info['organisation_id'] ) : '';
	}

	public function get_token_org_name() {
		$token_info = $this->get_token_info_block();
		if ( $token_info == false ) {
			return '';
		}
		return isset( $token_info['workspace'] ) ? ( $token_info['workspace'] ) : '';
	}

	public function get_token_team_id() {
		$token_info = $this->get_token_info_block();
		if ( $token_info == false ) {
			return '';
		}
		return isset( $token_info['team_id'] ) ? ( $token_info['team_id'] ) : '';
	}

	public function get_token_type() {
		$token_info = $this->get_token_info_block();
		if ( $token_info == false ) {
			return '';
		}
		return isset( $token_info['token_type'] ) ? ( $token_info['token_type'] ) : '';
	}

	public function get_token_name() {
		$token_info = $this->get_token_info_block();
		if ( $token_info == false ) {
			return '';
		}
		return isset( $token_info['name'] ) ? ( $token_info['name'] ) : '';
	}

	public function get_permalink(): string {
		return get_option( 'shorthand_permalink' );
	}

	public function get_post_css(): string {
		return get_option( 'shorthand_css' );
	}

	public function get_post_regex_list(): string {
		return get_option( 'shorthand_regex_list' );
	}

	public function get_v2_token() {
		$token = get_option( 'shorthand_v2_token' );
		return empty( $token ) ? '' : $token;
	}

	public function get_app_url() {
		return defined( 'THESHED_DEFAULT_APP_URL' ) ? THESHED_DEFAULT_APP_URL : 'https://app.shorthand.com';
	}

	public function get_api_url() {
		return defined( 'THESHED_DEFAULT_API_URL' ) ? THESHED_DEFAULT_API_URL : 'https://api.shorthand.com';
	}


	public function get_editor_url( $story_id ) {
		return $this->get_app_url() . '/organisations/' . $this->get_token_org_id() . '/stories/' . $story_id;
	}

	public function get_dashboard_url() {
		return $this->get_app_url() . '/organisations/' . $this->get_token_org_id();
	}

	public function is_verified() {
		return get_option( 'shorthand_v2_token_info' ) != false;
	}


	public function set_v2_next_signing_and_verifying_keys( array $signing_key, array $verifying_key ): void {
		update_option( 'shorthand_v2_next_signing_key', wp_json_encode( $signing_key ) );
		update_option( 'shorthand_v2_next_verifying_key', wp_json_encode( $verifying_key ) );
	}

	public function get_v2_next_signing_and_verifying_keys(): array {
		return array(
			json_decode( get_option( 'shorthand_v2_next_signing_key', '' ), true ),
			json_decode( get_option( 'shorthand_v2_next_verifying_key', '' ), true ),
		);
	}

	public function update_v2_signing_keys(): void {
		$signing_json   = get_option( 'shorthand_v2_next_signing_key', '' );
		$verifying_json = get_option( 'shorthand_v2_next_verifying_key', '' );

		update_option( 'shorthand_v2_signing_key', $signing_json );
		update_option( 'shorthand_v2_verifying_key', $verifying_json );
	}

	public function set_v2_signing_and_verifying_keys( array $signing_key, array $verifying_key ): void {
		update_option( 'shorthand_v2_signing_key', wp_json_encode( $signing_key ) );
		update_option( 'shorthand_v2_verifying_key', wp_json_encode( $verifying_key ) );
	}

	public function get_v2_signing_key(): array {
		return json_decode( get_option( 'shorthand_v2_signing_key', '' ), true );
	}

	public function get_v2_signing_and_verifying_keys(): array {
		return array(
			json_decode( get_option( 'shorthand_v2_signing_key', '' ), true ),
			json_decode( get_option( 'shorthand_v2_verifying_key', '' ), true ),
		);
	}

	public function is_publishing_async(): bool {
		return ! (bool) get_option( 'shorthand_disable_cron' );
	}

	/**
	 * On plugin activation, copy over any config from the old plugin, unless newer values exist.
	 */
	public function activate_plugin() {
		if ( ! get_option( 'shorthand_permalink', '' ) ) {
			/* fall back to the old permalink setting */
			$old_permalink = get_option( 'sh_permalink' );
			add_option( 'shorthand_permalink', $old_permalink, '', true );
		}

		if ( ! get_option( 'shorthand_regex_list', '' ) ) {
			/* the regex list in the old plugin is stored base64 encoded */
			$old_regex_list = base64_decode( get_option( 'sh_regex_list', '' ) );
			add_option( 'shorthand_regex_list', $old_regex_list, '', true );
		}

		if ( ! get_option( 'shorthand_css', '' ) ) {
			$old_css = wp_kses_no_null( get_option( 'sh_css', $this->get_default_css() ) );
			add_option( 'shorthand_css', $old_css, '', true );
		}
	}
}
