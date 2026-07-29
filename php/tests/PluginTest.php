<?php

declare(strict_types=1);

namespace Shorthand\Tests;

use Shorthand\Core\Version;
use Shorthand\Plugin;
use Shorthand\Plugin\Dependencies;
use Shorthand\Plugin\PostType;
use Shorthand\Services\Options;
use Shorthand\Services\StoryKses;

final class PluginTest extends WordPressTestCase {

	public function test_activation_registers_the_post_type_before_flushing_rewrite_rules(): void {
		$options = $this->createMock( Options::class );
		$options
			->expects( $this->once() )
			->method( 'activate_plugin' );
		$options
			->expects( $this->once() )
			->method( 'get_v2_token' )
			->willReturn( '' );

		$post_type = $this->createMock( PostType::class );
		$post_type
			->expects( $this->once() )
			->method( 'register_post_type' );

		$dependencies = $this->createMock( Dependencies::class );
		$dependencies
			->method( 'get_options' )
			->willReturn( $options );
		$dependencies
			->method( 'get_post_type' )
			->willReturn( $post_type );

		$plugin = new Plugin( $dependencies, $this->createMock( StoryKses::class ) );
		$plugin->activate();

		$this->assertSame( 1, \tests_wp_rewrite_flushes() );
	}

	public function test_check_for_updates_returns_original_value_when_transient_is_not_an_object(): void {
		$plugin = $this->make_plugin();

		$this->assertSame( 'not-an-object', $plugin->check_for_updates( 'not-an-object' ) );
		$this->assertSame( array(), \tests_wp_remote_requests() );
	}

	public function test_check_for_updates_adds_available_update_to_response(): void {
		$plugin = $this->make_plugin();

		\tests_wp_set_remote_response(
			array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'version'      => '1.1.0',
						'homepage'     => 'https://example.test/plugin',
						'download_url' => 'https://example.test/plugin.zip',
						'tested'       => '6.8',
						'requires'     => '6.0',
						'requires_php' => '7.4',
						'icons'        => array(
							'default' => 'https://example.test/icon.png',
						),
					)
				),
			)
		);

		$transient = (object) array(
			'response'  => array(),
			'no_update' => array(),
		);

		$result        = $plugin->check_for_updates( $transient );
		$plugin_key    = ( new Version() )->get_plugin_base_name();
		$plugin_update = $result->response[ $plugin_key ];

		$this->assertArrayHasKey( $plugin_key, $result->response );
		$this->assertSame( 'the-shorthand-editor', $plugin_update->slug );
		$this->assertSame( '1.1.0', $plugin_update->new_version );
		$this->assertSame( 'https://example.test/plugin.zip', $plugin_update->package );
		$this->assertSame(
			array(
				'default' => 'https://example.test/icon.png',
			),
			$plugin_update->icons
		);
		$this->assertSame( 12 * HOUR_IN_SECONDS, \tests_wp_get_transient_ttl( 'theshed_update_info' ) );
		$this->assertSame(
			array(
				array(
					'url'  => 'https://example.test/update.json',
					'args' => array(
						'timeout' => 10,
					),
				),
			),
			\tests_wp_remote_requests()
		);
	}

	public function test_check_for_updates_caches_remote_failures(): void {
		$plugin = $this->make_plugin();

		\tests_wp_set_remote_response( new \WP_Error( 'http_error', 'Lookup failed' ) );

		$transient = (object) array(
			'response'  => array(),
			'no_update' => array(),
		);

		$result = $plugin->check_for_updates( $transient );

		$this->assertSame( array(), $result->response );
		$this->assertSame( array(), $result->no_update );
		$this->assertSame( 'error', \tests_wp_get_transient( 'theshed_update_info' ) );
		$this->assertSame( HOUR_IN_SECONDS, \tests_wp_get_transient_ttl( 'theshed_update_info' ) );
	}

	public function test_plugin_info_returns_remote_metadata_for_matching_slug(): void {
		$plugin = $this->make_plugin();

		\tests_wp_set_transient(
			'theshed_update_info',
			(object) array(
				'version'        => '1.1.0',
				'name'           => 'The Shorthand Editor',
				'author'         => 'Shorthand',
				'author_profile' => 'https://example.test/team',
				'homepage'       => 'https://example.test/plugin',
				'requires'       => '6.0',
				'tested'         => '6.8',
				'requires_php'   => '7.4',
				'download_url'   => 'https://example.test/plugin.zip',
				'sections'       => (object) array(
					'description' => 'A plugin description.',
				),
				'banners'        => (object) array(
					'low' => 'https://example.test/banner.png',
				),
				'icons'          => (object) array(
					'default' => 'https://example.test/icon.png',
				),
			)
		);

		$result = $plugin->plugin_info(
			null,
			'plugin_information',
			(object) array(
				'slug' => 'the-shorthand-editor',
			)
		);

		$this->assertIsObject( $result );
		$this->assertSame( 'The Shorthand Editor', $result->name );
		$this->assertSame( '1.1.0', $result->version );
		$this->assertSame( 'https://example.test/plugin.zip', $result->download_link );
		$this->assertSame(
			array(
				'description' => 'A plugin description.',
			),
			$result->sections
		);
	}

	public function test_block_upgrade_returns_wordpress_error_for_the_plugin(): void {
		$plugin = $this->make_plugin();

		$result = $plugin->block_upgrade(
			true,
			array(
				'plugin' => ( new Version() )->get_plugin_base_name(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'upgrade_blocked', $result->get_error_code() );
		$this->assertSame( 'Plugin upgrades are disabled in this environment.', $result->get_error_message() );
	}

	private function make_plugin(): Plugin {
		$plugin = $this->instantiateWithoutConstructor( Plugin::class );

		$this->setPrivateProperty(
			$plugin,
			'options',
			new class {
				public function get_update_url(): string {
					return 'https://example.test/update.json';
				}
			}
		);
		$this->setPrivateProperty( $plugin, 'version', new Version() );

		return $plugin;
	}
}
