<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Services\Options;

/**
 * Manages token operations and coordinates between Options and Shorthand services.
 *
 * When a token is added or updated, this class fetches the token info from the
 * Shorthand API and updates the persistent authorisation state accordingly.
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
	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	public function __construct( Options $options, Shorthand $shorthand, AuthStateManager $auth_state_manager ) {
		$this->options            = $options;
		$this->shorthand          = $shorthand;
		$this->auth_state_manager = $auth_state_manager;
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
	 * Fetch token info from API and store it, updating the auth state.
	 *
	 * When the token is empty the state becomes `disconnected` and the cached
	 * token info is cleared.  When a non-empty token is provided the API is
	 * queried; on success the state becomes `connected`, and on failure the
	 * state is set by the API response interceptor in ShorthandApiClient (401
	 * or 426).  Neither the token nor the token info are cleared on failure.
	 */
	public function fetch_and_store_token_info( $token ) {
		if ( empty( $token ) ) {
			delete_option( 'shorthand_v2_token_info' );
			$this->auth_state_manager->set_state( AuthStateManager::STATE_DISCONNECTED );
			return;
		}

		$token_info = $this->shorthand->fetch_token_info( $token );

		if ( $token_info && ! is_wp_error( $token_info ) ) {
			update_option( 'shorthand_v2_token_info', $token_info );
			$this->auth_state_manager->set_state( AuthStateManager::STATE_CONNECTED );
		}

		/*
		 * On failure the auth state is already updated by the response
		 * interceptor in ShorthandApiClient (401 → invalid, 426 →
		 * upgrade_required).  We intentionally do NOT clear token_info here
		 * so the UI retains workspace context for informational purposes.
		 */
	}
}
