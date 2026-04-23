<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Plugin;
use Shorthand\Plugin\Dependencies;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\StoryKses;
use Shorthand\Tests\WordPressTestCase;

final class PluginBootstrapTest extends WordPressTestCase {

	public function test_plugin_init_boots_dependencies_and_story_kses(): void {
		$dependencies = $this->createMock( Dependencies::class );
		$story_kses   = $this->createMock( StoryKses::class );
		$options      = new TestOptions();
		$post_type    = new TestPostType();
		$cron         = new TestCron();
		$version      = new \Shorthand\Core\Version();

		$dependencies
			->expects( $this->once() )
			->method( 'boot' );

		$dependencies
			->expects( $this->once() )
			->method( 'get_options' )
			->willReturn( $options );

		$dependencies
			->expects( $this->once() )
			->method( 'get_version' )
			->willReturn( $version );

		$dependencies
			->expects( $this->once() )
			->method( 'get_post_type' )
			->willReturn( $post_type );

		$dependencies
			->expects( $this->once() )
			->method( 'get_auth_state_manager' )
			->willReturn( new AuthStateManager( $version ) );

		$dependencies
			->expects( $this->once() )
			->method( 'get_cron' )
			->willReturn( $cron );

		$story_kses
			->expects( $this->once() )
			->method( 'init' );

		$plugin = new Plugin( $dependencies, $story_kses );

		$plugin->init();
	}

	public function test_init_seeds_connected_when_state_unset_and_token_present(): void {
		\tests_wp_set_option( 'shorthand_v2_token', 'a-valid-token' );

		$plugin = $this->build_plugin_for_seeding_test( $auth_state_manager );

		$plugin->init();

		$this->assertSame( AuthStateManager::STATE_CONNECTED, $auth_state_manager->get_state() );
	}

	public function test_init_leaves_state_unset_when_state_unset_and_token_missing(): void {
		$plugin = $this->build_plugin_for_seeding_test( $auth_state_manager );

		$plugin->init();

		$this->assertFalse( \get_option( AuthStateManager::OPTION_KEY ) );
		$this->assertSame( AuthStateManager::STATE_NEVER_CONNECTED, $auth_state_manager->get_state() );
	}

	public function test_init_does_not_touch_auth_state_when_already_initialised(): void {
		\tests_wp_set_option(
			AuthStateManager::OPTION_KEY,
			array(
				'state'           => AuthStateManager::STATE_INVALID,
				'changed_at'      => 42,
				'pending_upgrade' => false,
			)
		);
		\tests_wp_set_option( 'shorthand_v2_token', 'a-valid-token' );

		$plugin = $this->build_plugin_for_seeding_test( $auth_state_manager );

		$plugin->init();

		$stored = \get_option( AuthStateManager::OPTION_KEY );
		$this->assertSame( AuthStateManager::STATE_INVALID, $stored['state'] );
		$this->assertSame( 42, $stored['changed_at'] );
	}

	/**
	 * Wire up a Plugin with a real Options and AuthStateManager so the
	 * seeding path can be exercised end-to-end against the in-memory
	 * options store.
	 *
	 * @param AuthStateManager|null $auth_state_manager Out-parameter; receives
	 *        the AuthStateManager that the Plugin's dependencies will return.
	 */
	private function build_plugin_for_seeding_test( ?AuthStateManager &$auth_state_manager ): Plugin {
		$version            = new \Shorthand\Core\Version();
		$options            = new \Shorthand\Services\Options( $version );
		$auth_state_manager = new AuthStateManager( $version );

		$dependencies = $this->createMock( Dependencies::class );
		$dependencies->method( 'get_options' )->willReturn( $options );
		$dependencies->method( 'get_version' )->willReturn( $version );
		$dependencies->method( 'get_post_type' )->willReturn( new TestPostType() );
		$dependencies->method( 'get_cron' )->willReturn( new TestCron() );
		$dependencies->method( 'get_auth_state_manager' )->willReturn( $auth_state_manager );

		$story_kses = $this->createMock( StoryKses::class );

		return new Plugin( $dependencies, $story_kses );
	}
}
