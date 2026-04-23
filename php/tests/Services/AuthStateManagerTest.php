<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
use Shorthand\Services\AuthStateManager;
use Shorthand\Tests\WordPressTestCase;

final class AuthStateManagerTest extends WordPressTestCase {

	/** Create an AuthStateManager with a real or provided Version. */
	private function make_manager( ?Version $version = null ): AuthStateManager {
		return new AuthStateManager( $version ?? new Version() );
	}

	/**
	 * Read the raw persisted option, falling back to defaults.
	 *
	 * @return array{state: string, changed_at: int, pending_upgrade: bool}
	 */
	private function get_stored_option(): array {
		$option = \get_option( AuthStateManager::OPTION_KEY, false );
		if ( ! is_array( $option ) ) {
			return array(
				'state'           => AuthStateManager::STATE_NEVER_CONNECTED,
				'changed_at'      => 0,
				'pending_upgrade' => false,
			);
		}
		return $option;
	}

	/** Seed the auth state option directly. */
	private function set_state_option( string $state, int $changed_at = 1000, bool $pending_upgrade = false ): void {
		\tests_wp_set_option(
			AuthStateManager::OPTION_KEY,
			array(
				'state'           => $state,
				'changed_at'      => $changed_at,
				'pending_upgrade' => $pending_upgrade,
			)
		);
	}

	/**
	 * Build a minimal WordPress HTTP response array.
	 *
	 * @return array<string, mixed>
	 */
	private function make_http_response( int $status_code ): array {
		return array(
			'response' => array(
				'code' => $status_code,
			),
			'body'     => '',
		);
	}

	/** Populate the update_plugins transient with a newer version of this plugin. */
	private function set_update_available(): void {
		$version    = new Version();
		$base_name  = $version->get_plugin_base_name();

		\set_site_transient(
			'update_plugins',
			(object) array(
				'response' => array(
					$base_name => (object) array(
						'slug'        => $version->get_plugin_slug(),
						'new_version' => '99.0.0',
						'package'     => 'https://example.test/plugin.zip',
					),
				),
			)
		);
	}

	/** Remove all site transients so no plugin update is known. */
	private function clear_update_transient(): void {
		$GLOBALS['tests_wp_state']['site_transients'] = array();
	}

	/**
	 * A fresh manager with no stored option defaults to never_connected.
	 *
	 * @group default-state
	 */
	public function test_default_state_is_never_connected(): void {
		$manager = $this->make_manager();

		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $manager->get_state() );
	}

	/**
	 * Default changed_at is zero.
	 *
	 * @group default-state
	 */
	public function test_default_changed_at_is_zero(): void {
		$manager = $this->make_manager();

		$this->assertSame( 0, $manager->get_changed_at() );
	}

	/**
	 * is_connected() is false when no option exists.
	 *
	 * @group default-state
	 */
	public function test_is_connected_returns_false_by_default(): void {
		$manager = $this->make_manager();

		$this->assertFalse( $manager->is_connected() );
	}

	/**
	 * requires_upgrade() is false when no option exists.
	 *
	 * @group default-state
	 */
	public function test_requires_upgrade_returns_false_by_default(): void {
		$manager = $this->make_manager();

		$this->assertFalse( $manager->requires_upgrade() );
	}

	/**
	 * set_state() persists the state, a fresh timestamp, and clears pending_upgrade.
	 *
	 * @group set-state
	 */
	public function test_set_state_persists_state_and_timestamp(): void {
		$manager = $this->make_manager();

		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_CONNECTED, $stored['state'] );
		$this->assertGreaterThan( 0, $stored['changed_at'] );
		$this->assertFalse( $stored['pending_upgrade'] );
	}

	/**
	 * Repeated set_state() with the same value does not write.
	 *
	 * @group set-state
	 */
	public function test_set_state_is_idempotent(): void {
		$manager = $this->make_manager();

		$this->set_state_option( AuthStateManager::STATE_CONNECTED, 42 );

		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$stored = $this->get_stored_option();
		$this->assertSame( 42, $stored['changed_at'], 'Timestamp should not change when state is unchanged' );
	}

	/**
	 * Transitioning to a different state updates the timestamp.
	 *
	 * @group set-state
	 */
	public function test_set_state_updates_timestamp_on_transition(): void {
		$manager = $this->make_manager();

		$this->set_state_option( AuthStateManager::STATE_CONNECTED, 42 );

		$manager->set_state( AuthStateManager::STATE_INVALID );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertGreaterThan( 42, $stored['changed_at'] );
	}

	/**
	 * Any state transition clears pending_upgrade.
	 *
	 * @group set-state
	 */
	public function test_set_state_clears_pending_upgrade(): void {
		$manager = $this->make_manager();

		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );

		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$stored = $this->get_stored_option();
		$this->assertFalse( $stored['pending_upgrade'] );
	}

	/**
	 * A write occurs when only pending_upgrade differs.
	 *
	 * @group set-state
	 */
	public function test_set_state_writes_when_only_pending_upgrade_differs(): void {
		$manager = $this->make_manager();

		$this->set_state_option( AuthStateManager::STATE_INVALID, 42, true );

		$manager->set_state( AuthStateManager::STATE_INVALID );

		$stored = $this->get_stored_option();
		$this->assertFalse( $stored['pending_upgrade'], 'set_state should clear pending_upgrade even when state matches' );
		$this->assertSame( 42, $stored['changed_at'], 'Timestamp preserved because state string is unchanged' );
	}

	/**
	 * is_connected() returns true only for the connected state.
	 *
	 * @group convenience-checks
	 */
	public function test_is_connected_returns_true_when_connected(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$this->assertTrue( $manager->is_connected() );
	}

	/**
	 * is_connected() returns false for invalid.
	 *
	 * @group convenience-checks
	 */
	public function test_is_connected_returns_false_when_invalid(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_INVALID );

		$this->assertFalse( $manager->is_connected() );
	}

	/**
	 * requires_upgrade() returns true for upgrade_required.
	 *
	 * @group convenience-checks
	 */
	public function test_requires_upgrade_returns_true_when_upgrade_required(): void {
		$manager = $this->make_manager();
		$this->set_update_available();
		$manager->set_state( AuthStateManager::STATE_UPGRADE_REQUIRED );

		$this->assertTrue( $manager->requires_upgrade() );
	}

	/**
	 * Transport-level failures (WP_Error) are ignored.
	 *
	 * @group intercept-response
	 */
	public function test_intercept_response_ignores_wp_error(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$manager->intercept_response( new \WP_Error( 'timeout', 'Connection timed out' ) );

		$this->assertSame( AuthStateManager::STATE_CONNECTED, $manager->get_state() );
	}

	/**
	 * A 200 does not alter the state.
	 *
	 * @group intercept-response
	 */
	public function test_intercept_response_ignores_200(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$manager->intercept_response( $this->make_http_response( 200 ) );

		$this->assertSame( AuthStateManager::STATE_CONNECTED, $manager->get_state() );
	}

	/**
	 * A 500 does not alter the state.
	 *
	 * @group intercept-response
	 */
	public function test_intercept_response_ignores_500(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$manager->intercept_response( $this->make_http_response( 500 ) );

		$this->assertSame( AuthStateManager::STATE_CONNECTED, $manager->get_state() );
	}

	/**
	 * A 401 transitions to invalid from a connected state.
	 *
	 * @group intercept-401
	 */
	public function test_401_sets_invalid_from_connected(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$manager->intercept_response( $this->make_http_response( 401 ) );

		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
	}

	/**
	 * A 401 does not downgrade upgrade_required.
	 *
	 * @group intercept-401
	 */
	public function test_401_does_not_overwrite_upgrade_required(): void {
		$manager = $this->make_manager();
		$this->set_update_available();
		$manager->set_state( AuthStateManager::STATE_UPGRADE_REQUIRED );

		$manager->intercept_response( $this->make_http_response( 401 ) );

		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $manager->get_state() );
	}

	/**
	 * A 401 never sets pending_upgrade.
	 *
	 * @group intercept-401
	 */
	public function test_401_does_not_set_pending_upgrade(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$manager->intercept_response( $this->make_http_response( 401 ) );

		$stored = $this->get_stored_option();
		$this->assertFalse( $stored['pending_upgrade'] );
	}

	/**
	 * A 426 with an available update transitions to upgrade_required.
	 *
	 * @group intercept-426
	 */
	public function test_426_with_update_sets_upgrade_required(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$this->set_update_available();

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $manager->get_state() );
	}

	/**
	 * A 426 with an available update clears pending_upgrade.
	 *
	 * @group intercept-426
	 */
	public function test_426_with_update_clears_pending_upgrade(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );
		$this->set_update_available();

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$stored = $this->get_stored_option();
		$this->assertFalse( $stored['pending_upgrade'] );
	}

	/**
	 * A 426 without an available update falls through to invalid with pending_upgrade.
	 *
	 * @group intercept-426
	 */
	public function test_426_without_update_sets_invalid_with_pending_upgrade(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$this->clear_update_transient();

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertTrue( $stored['pending_upgrade'] );
	}

	/**
	 * Repeated 426-without-update does not re-write.
	 *
	 * @group intercept-426
	 */
	public function test_426_without_update_is_idempotent(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 42, true );
		$this->clear_update_transient();

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$stored = $this->get_stored_option();
		$this->assertSame( 42, $stored['changed_at'], 'Timestamp should not change on repeat 426-without-update' );
		$this->assertTrue( $stored['pending_upgrade'] );
	}

	/**
	 * get_state() promotes to upgrade_required when pending_upgrade is set and an update appears.
	 *
	 * @group lazy-promotion
	 */
	public function test_get_state_promotes_to_upgrade_required_when_update_appears(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );

		$this->set_update_available();

		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $manager->get_state() );
	}

	/**
	 * Lazy promotion persists the new state and clears the flag.
	 *
	 * @group lazy-promotion
	 */
	public function test_lazy_promotion_persists_the_new_state(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );

		$this->set_update_available();
		$manager->get_state();

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $stored['state'] );
		$this->assertFalse( $stored['pending_upgrade'] );
	}

	/**
	 * get_changed_at() triggers the same lazy promotion as get_state().
	 *
	 * Callers that consult `get_changed_at()` without first calling
	 * `get_state()` must still see the promoted timestamp — otherwise a
	 * user who dismissed the `invalid` notice would never see the
	 * follow-up `upgrade_required` notice.
	 *
	 * @group lazy-promotion
	 */
	public function test_get_changed_at_promotes_to_upgrade_required_when_update_appears(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );

		$this->set_update_available();

		$changed_at = $manager->get_changed_at();

		$this->assertGreaterThan( 100, $changed_at, 'changed_at should reflect the promotion time, not the stale invalid timestamp' );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $stored['state'] );
		$this->assertFalse( $stored['pending_upgrade'] );
	}

	/**
	 * No promotion when pending_upgrade is false.
	 *
	 * @group lazy-promotion
	 */
	public function test_lazy_promotion_does_not_fire_without_pending_upgrade(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, false );

		$this->set_update_available();

		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
	}

	/**
	 * No promotion when no update is available.
	 *
	 * @group lazy-promotion
	 */
	public function test_lazy_promotion_does_not_fire_without_update(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );
		$this->clear_update_transient();

		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
	}

	/**
	 * Connected -> 401 -> reconnect restores the connected state.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_connected_then_401_then_reconnect(): void {
		$manager = $this->make_manager();

		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$this->assertTrue( $manager->is_connected() );

		$manager->intercept_response( $this->make_http_response( 401 ) );
		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
		$this->assertFalse( $manager->is_connected() );

		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$this->assertTrue( $manager->is_connected() );
	}

	/**
	 * 426 without update, then update appears, triggers lazy promotion.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_426_without_update_then_update_appears(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$this->clear_update_transient();
		$manager->intercept_response( $this->make_http_response( 426 ) );

		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
		$this->assertFalse( $manager->requires_upgrade() );

		$this->set_update_available();

		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $manager->get_state() );
		$this->assertTrue( $manager->requires_upgrade() );
	}

	/**
	 * Reconnecting after 426 clears pending_upgrade, preventing later promotion.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_426_without_update_then_user_reconnects_then_update_appears(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$this->clear_update_transient();
		$manager->intercept_response( $this->make_http_response( 426 ) );
		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );

		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$stored = $this->get_stored_option();
		$this->assertFalse( $stored['pending_upgrade'] );

		$this->set_update_available();
		$this->assertSame( AuthStateManager::STATE_CONNECTED, $manager->get_state() );
	}

	/**
	 * A 401 after a 426-with-update does not downgrade.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_426_with_update_then_401(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$this->set_update_available();

		$manager->intercept_response( $this->make_http_response( 426 ) );
		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $manager->get_state() );

		$manager->intercept_response( $this->make_http_response( 401 ) );
		$this->assertSame( AuthStateManager::STATE_UPGRADE_REQUIRED, $manager->get_state() );
	}

	/**
	 * Repeated 426-without-update preserves the original timestamp.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_multiple_426_without_update_preserves_timestamp(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );
		$this->clear_update_transient();

		$manager->intercept_response( $this->make_http_response( 426 ) );
		$first_timestamp = $this->get_stored_option()['changed_at'];

		$manager->intercept_response( $this->make_http_response( 426 ) );
		$second_timestamp = $this->get_stored_option()['changed_at'];

		$this->assertSame( $first_timestamp, $second_timestamp );
	}

	/**
	 * A 401 then 426-without-update sets pending_upgrade on the second.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_401_then_426_without_update(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$manager->intercept_response( $this->make_http_response( 401 ) );
		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertFalse( $stored['pending_upgrade'] );

		$this->clear_update_transient();
		$manager->intercept_response( $this->make_http_response( 426 ) );
		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertTrue( $stored['pending_upgrade'] );
	}

	/**
	 * A 426 from disconnected sets invalid with pending_upgrade.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_disconnected_then_426_without_update(): void {
		$manager = $this->make_manager();
		$this->clear_update_transient();

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertTrue( $stored['pending_upgrade'] );
	}

	/**
	 * After upgrade_required, a plugin update and token refetch restores connected.
	 *
	 * @group transition-sequence
	 */
	public function test_sequence_upgrade_required_then_plugin_updated_and_token_refetched(): void {
		$manager = $this->make_manager();
		$this->set_update_available();

		$manager->intercept_response( $this->make_http_response( 426 ) );
		$this->assertTrue( $manager->requires_upgrade() );

		$this->clear_update_transient();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		$this->assertTrue( $manager->is_connected() );
		$this->assertFalse( $manager->requires_upgrade() );
	}

	/**
	 * A transient missing the response key means no update is available.
	 *
	 * @group update-transient
	 */
	public function test_update_transient_with_no_response_key(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		\set_site_transient( 'update_plugins', (object) array( 'no_update' => array() ) );

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertTrue( $stored['pending_upgrade'] );
	}

	/**
	 * An update for a different plugin does not count.
	 *
	 * @group update-transient
	 */
	public function test_update_transient_with_different_plugin(): void {
		$manager = $this->make_manager();
		$manager->set_state( AuthStateManager::STATE_CONNECTED );

		\set_site_transient(
			'update_plugins',
			(object) array(
				'response' => array(
					'some-other-plugin/plugin.php' => (object) array(
						'new_version' => '2.0.0',
					),
				),
			)
		);

		$manager->intercept_response( $this->make_http_response( 426 ) );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertTrue( $stored['pending_upgrade'] );
	}

	/**
	 * A false transient (never fetched) means no update.
	 *
	 * @group update-transient
	 */
	public function test_update_transient_false_means_no_update(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 100, true );

		$this->clear_update_transient();

		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
	}

	/**
	 * A missing option degrades to never_connected with zero timestamp.
	 *
	 * @group malformed-option
	 */
	public function test_get_state_handles_missing_option(): void {
		$manager = $this->make_manager();

		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $manager->get_state() );
		$this->assertSame( 0, $manager->get_changed_at() );
	}

	/**
	 * A non-array option degrades to never_connected.
	 *
	 * @group malformed-option
	 */
	public function test_get_state_handles_non_array_option(): void {
		$manager = $this->make_manager();
		\tests_wp_set_option( AuthStateManager::OPTION_KEY, 'garbage' );

		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $manager->get_state() );
	}

	/**
	 * An array missing changed_at degrades to never_connected.
	 *
	 * @group malformed-option
	 */
	public function test_get_state_handles_partial_option(): void {
		$manager = $this->make_manager();
		\tests_wp_set_option( AuthStateManager::OPTION_KEY, array( 'state' => 'connected' ) );

		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $manager->get_state() );
	}

	/**
	 * set_state(disconnected) persists the disconnected state distinct from never_connected.
	 *
	 * @group set-state
	 */
	public function test_set_state_disconnected_persists_distinct_from_never_connected(): void {
		$manager = $this->make_manager();

		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $manager->get_state() );

		$manager->set_state( AuthStateManager::STATE_DISCONNECTED );

		$this->assertSame( AuthStateManager::STATE_DISCONNECTED, $manager->get_state() );
	}

	/**
	 * A legacy option without pending_upgrade defaults the flag to false.
	 *
	 * @group malformed-option
	 */
	public function test_missing_pending_upgrade_defaults_to_false(): void {
		$manager = $this->make_manager();
		\tests_wp_set_option(
			AuthStateManager::OPTION_KEY,
			array(
				'state'      => AuthStateManager::STATE_INVALID,
				'changed_at' => 100,
			)
		);

		$this->set_update_available();

		$this->assertSame( AuthStateManager::STATE_INVALID, $manager->get_state() );
	}

	/**
	 * On upgrade with no stored state and a token present, seed to connected.
	 *
	 * @group initialise-missing-state
	 */
	public function test_initialise_missing_state_with_token_sets_connected(): void {
		$manager = $this->make_manager();

		$manager->initialise_missing_state( true );

		$this->assertSame( AuthStateManager::STATE_CONNECTED, $manager->get_state() );
	}

	/**
	 * An unset state with no token is indistinguishable from a fresh install,
	 * so initialise_missing_state does not persist anything and get_state()
	 * keeps returning the never_connected default.
	 *
	 * @group initialise-missing-state
	 */
	public function test_initialise_missing_state_without_token_is_no_op(): void {
		$manager = $this->make_manager();

		$manager->initialise_missing_state( false );

		$this->assertFalse( \get_option( AuthStateManager::OPTION_KEY ) );
		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $manager->get_state() );
	}

	/**
	 * When an auth state is already stored, initialise_missing_state is a no-op.
	 *
	 * @group initialise-missing-state
	 */
	public function test_initialise_missing_state_preserves_existing_state(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_INVALID, 42 );

		$manager->initialise_missing_state( true );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertSame( 42, $stored['changed_at'] );
	}

	/**
	 * Idempotent: an existing never_connected state is not overwritten.
	 *
	 * @group initialise-missing-state
	 */
	public function test_initialise_missing_state_preserves_never_connected(): void {
		$manager = $this->make_manager();
		$this->set_state_option( AuthStateManager::STATE_NEVER_CONNECTED, 42 );

		$manager->initialise_missing_state( false );

		$stored = $this->get_stored_option();
		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $stored['state'] );
		$this->assertSame( 42, $stored['changed_at'] );
	}
}
