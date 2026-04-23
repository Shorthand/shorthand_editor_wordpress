<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;

/**
 * Manages the persistent authorisation state of the plugin.
 *
 * The plugin operates in one of five modes based on its relationship with the
 * Shorthand API:
 *
 * - `never_connected`   — the plugin has just been activated and has never
 *                         been connected to a Shorthand workspace.  This is
 *                         the default on a fresh install, and the UI shows a
 *                         welcome message inviting the user to connect.
 * - `disconnected`      — the user has explicitly disconnected from
 *                         Shorthand, clearing the token.  The UI invites the
 *                         user to reconnect without signalling an error.
 * - `connected`         — the token has been validated successfully.
 * - `invalid`           — the API rejected the token (HTTP 401), or a 426 was
 *                         received but no plugin update is available yet.
 * - `upgrade_required`  — the API indicated the plugin version is too old
 *                         (HTTP 426) and an update is available to install.
 *
 * State changes are timestamped so the UI can indicate how long the plugin has
 * been in a given state.
 *
 * When a 426 is received but no update is available, the state becomes
 * `invalid` with a `pending_upgrade` flag.  On subsequent calls to
 * `get_state()`, if an update has appeared in the WordPress transient the
 * state is automatically promoted to `upgrade_required`.
 */
class AuthStateManager {

	const STATE_NEVER_CONNECTED  = 'never_connected';
	const STATE_DISCONNECTED     = 'disconnected';
	const STATE_CONNECTED        = 'connected';
	const STATE_INVALID          = 'invalid';
	const STATE_UPGRADE_REQUIRED = 'upgrade_required';

	const OPTION_KEY = 'shorthand_auth_state';

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	public function __construct( Version $version ) {
		$this->version = $version;
	}

	/**
	 * Return the current authorisation state.
	 *
	 * Performs lazy promotion: if a 426 was previously received without an
	 * available update (`pending_upgrade` flag), and an update has since
	 * appeared, the state is promoted to `upgrade_required` on the spot.
	 */
	public function get_state(): string {
		return $this->resolve_current_option()['state'];
	}

	/**
	 * Return the Unix timestamp of the most recent state change.
	 *
	 * Performs the same lazy promotion as `get_state()` so the timestamp
	 * reflects the current (possibly just-promoted) state rather than a
	 * stale `pending_upgrade` snapshot.  This matters for dismissal
	 * comparisons: a user who dismisses the `invalid` notice must still see
	 * the follow-up `upgrade_required` notice once an update appears.
	 */
	public function get_changed_at(): int {
		return $this->resolve_current_option()['changed_at'];
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
	 * Seed the auth state when upgrading from a plugin version that did not
	 * persist one.
	 *
	 * An unset state option is semantically equivalent to a fresh install —
	 * `get_state()` returns `never_connected` in that case via the default
	 * fallback.  This method therefore only takes action when a v2 token is
	 * present and no state is stored, promoting the install to `connected`
	 * to reflect the pre-upgrade reality.  Missing state with no token is
	 * indistinguishable from a fresh install and is left untouched.
	 *
	 * If the carried-over token turns out to be invalid, the API response
	 * interceptor will demote the state to `invalid` or `upgrade_required`
	 * on the next request.
	 *
	 * @param bool $has_token Whether a non-empty v2 token is currently stored.
	 */
	public function initialise_missing_state( bool $has_token ): void {
		if ( false !== get_option( self::OPTION_KEY ) ) {
			return;
		}
		if ( $has_token ) {
			$this->set_state( self::STATE_CONNECTED );
		}
	}

	/**
	 * Transition to a new state.
	 *
	 * The write is skipped when the state has not actually changed, so this
	 * method is safe to call on every API response.  The `pending_upgrade`
	 * flag is always cleared on transition — only `intercept_response()` sets
	 * it on the specific 426-without-update path.
	 */
	public function set_state( string $state ): void {
		$option = $this->get_option();

		if ( $option['state'] === $state && ! $option['pending_upgrade'] ) {
			return;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'state'           => $state,
				'changed_at'      => $option['state'] === $state ? $option['changed_at'] : time(),
				'pending_upgrade' => false,
			)
		);
	}

	/**
	 * Inspect an HTTP response and update the auth state when the API signals
	 * an authorisation or compatibility problem.
	 *
	 * A 426 (Upgrade Required) sets `upgrade_required` only when a plugin
	 * update is known to be available.  Otherwise the state becomes `invalid`
	 * with a `pending_upgrade` flag so that `get_state()` can promote it
	 * later when an update appears.
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
			if ( $this->has_update_available() ) {
				$this->set_state( self::STATE_UPGRADE_REQUIRED );
			} else {
				$this->set_state_with_pending_upgrade();
			}
			return;
		}

		if ( 401 === $status_code && ! $this->requires_upgrade() ) {
			$this->set_state( self::STATE_INVALID );
		}
	}

	/**
	 * Read the stored option and apply lazy `pending_upgrade` promotion.
	 *
	 * When a 426 was previously recorded but no plugin update was available,
	 * the state is stored as `invalid` with `pending_upgrade = true`.  Once
	 * an update becomes available, calling this method promotes the state
	 * to `upgrade_required` (updating `changed_at`) and returns the fresh
	 * option so callers see a consistent view.
	 *
	 * @return array{state: string, changed_at: int, pending_upgrade: bool}
	 */
	private function resolve_current_option(): array {
		$option = $this->get_option();

		if ( $option['pending_upgrade'] && $this->has_update_available() ) {
			$this->set_state( self::STATE_UPGRADE_REQUIRED );
			return $this->get_option();
		}

		return $option;
	}

	/**
	 * Check whether a newer version of this plugin is available in the
	 * WordPress update transient.
	 */
	private function has_update_available(): bool {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response ) ) {
			return false;
		}
		return isset( $transient->response[ $this->version->get_plugin_base_name() ] );
	}

	/**
	 * Transition to `invalid` with the `pending_upgrade` flag set.
	 *
	 * This is used when a 426 is received but no plugin update is available
	 * yet.  The flag allows `get_state()` to promote to `upgrade_required`
	 * once an update appears.
	 */
	private function set_state_with_pending_upgrade(): void {
		$option = $this->get_option();

		if ( $option['state'] === self::STATE_INVALID && $option['pending_upgrade'] ) {
			return;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'state'           => self::STATE_INVALID,
				'changed_at'      => $option['state'] === self::STATE_INVALID ? $option['changed_at'] : time(),
				'pending_upgrade' => true,
			)
		);
	}

	/**
	 * Read the stored option, falling back to a sensible default.
	 *
	 * @return array{state: string, changed_at: int, pending_upgrade: bool}
	 */
	private function get_option(): array {
		$option = get_option( self::OPTION_KEY );

		if ( ! is_array( $option ) || ! isset( $option['state'], $option['changed_at'] ) ) {
			return array(
				'state'           => self::STATE_NEVER_CONNECTED,
				'changed_at'      => 0,
				'pending_upgrade' => false,
			);
		}

		return array(
			'state'           => $option['state'],
			'changed_at'      => $option['changed_at'],
			'pending_upgrade' => ! empty( $option['pending_upgrade'] ),
		);
	}
}
