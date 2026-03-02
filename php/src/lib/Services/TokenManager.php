<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Services\Options;

/**
 * Manages token operations and coordinates between Options and Shorthand services
 */
class TokenManager {

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;
	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;

	public function __construct( Options $options, Shorthand $shorthand ) {
		$this->options   = $options;
		$this->shorthand = $shorthand;
	}

	/**
	 * Initialize token event handlers
	 */
	public function init() {
		$loader = new Loader();

		// Listen for token changes
		$loader->add_action( 'add_option_shorthand_v2_token', $this, 'handle_token_added', 10, 2 );
		$loader->add_action( 'update_option_shorthand_v2_token', $this, 'handle_token_updated', 10, 3 );

		$loader->register();
	}

	/**
	 * Handle when a new token is added
	 */
	public function handle_token_added( $option_name, $token_value ) {
		$this->fetch_and_store_token_info( $token_value );
	}

	/**
	 * Handle when a token is updated
	 */
	public function handle_token_updated( $old_value, $new_value, $option_name ) {
		$this->fetch_and_store_token_info( $new_value );
	}

	/**
	 * Fetch token info from API and store it
	 */
	private function fetch_and_store_token_info( $token ) {
		// Clear existing token info
		delete_option( 'shorthand_v2_token_info' );

		if ( empty( $token ) ) {
			return;
		}

		// Use Shorthand service to fetch token info
		$token_info = $this->shorthand->fetch_token_info( $token );

		if ( $token_info && ! is_wp_error( $token_info ) ) {
			// Store the token info
			update_option( 'shorthand_v2_token_info', $token_info );

			// Clear any dismissed connect notices so they can reappear if the token is later removed.
			delete_metadata( 'user', 0, 'shorthand_connect_notice_dismissed', '', true );
		}
	}
}
