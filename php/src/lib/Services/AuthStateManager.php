<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the persistent authorisation state of the plugin.
 *
 * The plugin operates in one of four modes based on its relationship with the
 * Shorthand API:
 *
 * - `disconnected`      — no token is configured.
 * - `connected`         — the token has been validated successfully.
 * - `invalid`           — the API rejected the token (HTTP 401).
 * - `upgrade_required`  — the API indicated the plugin version is too old (HTTP 426).
 *
 * State changes are timestamped so the UI can indicate how long the plugin has
 * been in a given state.
 */
class AuthStateManager {

	const STATE_DISCONNECTED     = 'disconnected';
	const STATE_CONNECTED        = 'connected';
	const STATE_INVALID          = 'invalid';
	const STATE_UPGRADE_REQUIRED = 'upgrade_required';

	const OPTION_KEY = 'shorthand_auth_state';

	/**
	 * Return the current authorisation state.
	 */
	public function get_state(): string {
		$option = $this->get_option();
		return $option['state'];
	}

	/**
	 * Return the Unix timestamp of the most recent state change.
	 */
	public function get_changed_at(): int {
		$option = $this->get_option();
		return $option['changed_at'];
	}

	/**
	 * Convenience check: is the plugin in the `connected` state?
	 */
	public function is_connected(): bool {
		return self::STATE_CONNECTED === $this->get_state();
	}

	/**
	 * Convenience check: is the plugin in the `upgrade_required` state?
	 */
	public function requires_upgrade(): bool {
		return self::STATE_UPGRADE_REQUIRED === $this->get_state();
	}

	/**
	 * Transition to a new state.
	 *
	 * The write is skipped when the state has not actually changed, so this
	 * method is safe to call on every API response.
	 */
	public function set_state( string $state ): void {
		$option = $this->get_option();

		if ( $option['state'] === $state ) {
			return;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'state'      => $state,
				'changed_at' => time(),
			)
		);
	}

	/**
	 * Inspect an HTTP response and update the auth state when the API signals
	 * an authorisation or compatibility problem.
	 *
	 * A 426 (Upgrade Required) always takes precedence over a 401 because the
	 * plugin cannot recover from a version mismatch by re-authenticating.
	 *
	 * Transport-level failures (WP_Error) are intentionally ignored — a
	 * network timeout should not alter the persisted auth state.
	 *
	 * @param array|\WP_Error $response  The raw WordPress HTTP response.
	 */
	public function intercept_response( $response ): void {
		if ( is_wp_error( $response ) ) {
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 426 === $status_code ) {
			$this->set_state( self::STATE_UPGRADE_REQUIRED );
			return;
		}

		if ( 401 === $status_code && ! $this->requires_upgrade() ) {
			$this->set_state( self::STATE_INVALID );
		}
	}

	/**
	 * Read the stored option, falling back to a sensible default.
	 *
	 * @return array{state: string, changed_at: int}
	 */
	private function get_option(): array {
		$option = get_option( self::OPTION_KEY );

		if ( ! is_array( $option ) || ! isset( $option['state'], $option['changed_at'] ) ) {
			return array(
				'state'      => self::STATE_DISCONNECTED,
				'changed_at' => 0,
			);
		}

		return $option;
	}
}
