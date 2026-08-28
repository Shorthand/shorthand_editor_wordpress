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
		/* Source: Shorthand API, /v2/connect `apiToken`.  At rest: JWT, plain text. */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_token',
			array(
				'type'              => 'string',
				'label'             => __( 'Shorthand API token', 'the-shorthand-editor' ),
				'description'       => __( 'A Shorthand API token, associated with the connected workspace', 'the-shorthand-editor' ),
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		/* Source: settings form; legacy `sh_permalink` on activation.  At rest: URL path, slugged per segment. */
		register_setting(
			'theshed-general-options-group',
			'shorthand_permalink',
			array(
				'type'              => 'string',
				'label'             => __( 'Permalink structure', 'the-shorthand-editor' ),
				'description'       => __( 'Set the permalink structure for published Shorthand story posts', 'the-shorthand-editor' ),
				'sanitize_callback' => array( $this, 'sanitize_permalink' ),
				'default'           => 'story',
			)
		);

		/* Source: settings form; legacy base64 `sh_regex_list` on activation.  At rest: plain text, one rule per line. */
		register_setting(
			'theshed-general-options-group',
			'shorthand_regex_list',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_regex_list' ),
				'default'           => '',
			)
		);

		/* Source: settings form checkbox.  At rest: bool; forced on where uploads are remote. */
		register_setting(
			'theshed-general-options-group',
			'shorthand_disable_staging',
			array(
				'type'              => 'boolean',
				'label'             => __( 'Disable staging directory', 'the-shorthand-editor' ),
				'description'       => __( 'Unpack story archives straight into the uploads directory', 'the-shorthand-editor' ),
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		/* Source: settings form; legacy `sh_css`, else the bundled default stylesheet.  At rest: raw CSS, NULs stripped. */
		register_setting(
			'theshed-general-options-group',
			'shorthand_css',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_no_null',
				'default'           => '',
			)
		);

		/* Internal settings; not exposed to any settings form. */

		/* Source: AuthStateManager.  At rest: state constant, timestamp, flag. */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_auth_state',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_auth_state' ),
				'default'           => null,
			)
		);

		/*
		 * Source: Shorthand API, /v2/token-info.  At rest: HTML-escaped — the API
		 * returns entity-encoded workspace and team names, so the getters decode
		 * before the output layer escapes them again (PLA-2464).
		 */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_token_info',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_v2_token_info' ),
				'default'           => null,
			)
		);

		/* Source: generated locally, sodium Ed25519 keypair.  At rest: JSON-encoded JWK. */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_signing_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		/* Source: generated locally, sodium Ed25519 keypair.  At rest: JSON-encoded JWK. */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_verifying_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		/* Source: generated locally, sodium Ed25519 keypair, pending rotation.  At rest: JSON-encoded JWK. */
		register_setting(
			'theshed-internal-options-group',
			'shorthand_v2_next_signing_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			)
		);

		/* Source: generated locally, sodium Ed25519 keypair, pending rotation.  At rest: JSON-encoded JWK. */
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
		update_option( 'shorthand_flush_rewrite_rules', true );
	}

	public function handle_permalink_updated( $option, $old_value, $value ): void {
		update_option( 'shorthand_flush_rewrite_rules', true );
	}

	/**
	 * @param mixed $auth_state
	 * @return array{state: string, changed_at: int, pending_upgrade: bool}|null
	 */
	public function sanitize_auth_state( $auth_state ) {
		if ( ! is_array( $auth_state ) || ! isset( $auth_state['state'], $auth_state['changed_at'] ) ) {
			return null;
		}

		$valid_states = array(
			AuthStateManager::STATE_NEVER_CONNECTED,
			AuthStateManager::STATE_DISCONNECTED,
			AuthStateManager::STATE_CONNECTED,
			AuthStateManager::STATE_INVALID,
			AuthStateManager::STATE_UPGRADE_REQUIRED,
		);

		if ( ! in_array( $auth_state['state'], $valid_states, true ) ) {
			return null;
		}

		return array(
			'state'           => $auth_state['state'],
			'changed_at'      => absint( $auth_state['changed_at'] ),
			'pending_upgrade' => ! empty( $auth_state['pending_upgrade'] ),
		);
	}

	/**
	 * Reduces a token-info payload to the fields the plugin stores.
	 *
	 * @param mixed $token_info Decoded /v2/token-info response.
	 * @return array<string, string>|null Stored fields, or null for a payload that is not an array.
	 */
	public function sanitize_v2_token_info( $token_info ) {
		if ( ! is_array( $token_info ) ) {
			return null;
		}

		$fields = array( 'team_id', 'organisation_id', 'workspace', 'name', 'logo', 'token_type' );
		$result = array();

		/* A field the API omits is stored empty, so the getters stay total. */
		foreach ( $fields as $field ) {
			$result[ $field ] = isset( $token_info[ $field ] ) ? sanitize_text_field( (string) $token_info[ $field ] ) : '';
		}

		return $result;
	}

	/**
	 * Reduces the permalink setting to a URL path.
	 *
	 * The value becomes a post type rewrite slug, so it may span segments but
	 * must survive a URL.  Each segment is slugged and empty ones are dropped;
	 * a value with nothing left falls back to the default.
	 *
	 * @param mixed $permalink Submitted permalink structure.
	 */
	public function sanitize_permalink( $permalink ): string {
		$segments = array_filter( array_map( 'sanitize_title', explode( '/', (string) $permalink ) ) );

		return empty( $segments ) ? 'story' : implode( '/', $segments );
	}

	public function sanitize_regex_list( $regex_list ) {
		$regex_list = trim( $regex_list );

		if ( ! $regex_list ) {
			return $regex_list;
		}

		$error = RegexRuleSet::get_validation_error( $regex_list );
		if ( null !== $error ) {
			add_settings_error( 'shorthand_regex_list', 'INVALID_REGEX_LIST', $error );
			return;
		}

		return $regex_list;
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
		if ( false == $token_info ) {
			return '';
		}
		return isset( $token_info['workspace'] ) ? wp_specialchars_decode( $token_info['workspace'], ENT_QUOTES ) : '';
	}

	public function get_token_team_id() {
		$token_info = $this->get_token_info_block();
		if ( false == $token_info ) {
			return '';
		}
		return isset( $token_info['team_id'] ) ? ( $token_info['team_id'] ) : '';
	}

	public function get_token_type() {
		$token_info = $this->get_token_info_block();
		if ( false == $token_info ) {
			return '';
		}
		return isset( $token_info['token_type'] ) ? ( $token_info['token_type'] ) : '';
	}

	public function get_token_name() {
		$token_info = $this->get_token_info_block();
		if ( false == $token_info ) {
			return '';
		}
		return isset( $token_info['name'] ) ? wp_specialchars_decode( $token_info['name'], ENT_QUOTES ) : '';
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

	/**
	 * Reports whether story archives are unpacked in a staging directory first.
	 *
	 * Staging is the default, and cannot be turned off where uploads are
	 * remote: `ZipArchive::extractTo()` ignores stream wrappers, so unpacking
	 * straight into uploads would write nothing there.
	 */
	public function is_staging_enabled(): bool {
		if ( ! $this->can_disable_staging() ) {
			return true;
		}

		return ! get_option( 'shorthand_disable_staging', false );
	}

	/**
	 * Reports whether the staging setting is the author's to choose.
	 */
	public function can_disable_staging(): bool {
		return ! FileSystem::is_remote_uploads();
	}

	/**
	 * Reads a checkbox as a boolean.
	 *
	 * @param mixed $value Submitted value; absent when the box is unticked.
	 */
	public function sanitize_checkbox( $value ): bool {
		return ! empty( $value );
	}

	public function get_v2_token() {
		$token = get_option( 'shorthand_v2_token' );
		return empty( $token ) ? '' : $token;
	}

	public function get_update_url() {
		return defined( 'THESHED_UPDATE_URL' ) ? THESHED_UPDATE_URL : 'https://shorthand.com/plugins/wp/the-shorthand-editor/update.json';
	}

	public function get_app_url() {
		return defined( 'THESHED_APP_URL' ) ? THESHED_APP_URL : 'https://app.shorthand.com';
	}

	public function get_api_url() {
		return defined( 'THESHED_API_URL' ) ? THESHED_API_URL : 'https://api.shorthand.com';
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

	/**
	 * On plugin activation, copy over any config from the old plugin, unless newer values exist.
	 */
	public function activate_plugin() {
		if ( ! get_option( 'shorthand_permalink', '' ) ) {
			/* fall back to the old permalink setting */
			$old_permalink = get_option( 'sh_permalink', 'story' );
			$permalink     = $old_permalink ? $old_permalink : 'story';
			add_option( 'shorthand_permalink', $permalink, '', true );
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

		/* Clean up legacy notice dismissal meta from earlier plugin versions. */
		delete_metadata( 'user', 0, 'shorthand_connect_notice_dismissed', '', true );
	}

	/**
	 * Drops options left behind by earlier plugin versions.
	 *
	 * Runs on every request, because upgrading a plugin does not re-run its
	 * activation hook. Each option named here is autoloaded, so the check is
	 * free once the option has been dropped.
	 */
	public function remove_legacy_options(): void {
		/* Publishing is always asynchronous; the synchronous debug override is gone. */
		if ( null !== get_option( 'shorthand_disable_cron', null ) ) {
			delete_option( 'shorthand_disable_cron' );
		}
	}
}
