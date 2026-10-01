<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Plugin\DevHttp;
use Shorthand\Tests\WordPressTestCase;

final class DevHttpTest extends WordPressTestCase {

	private const HOOKS = array( 'http_request_host_is_external', 'http_allowed_safe_ports', 'https_ssl_verify' );

	public function test_the_api_host_passes_all_three_guards(): void {
		DevHttp::allow( 'https://Host.Docker.Internal:9443/api' );

		$this->assertTrue( $this->filter( 'http_request_host_is_external', false, 'host.docker.internal' ) );
		$this->assertSame(
			array( 80, 443, 8080, 9443 ),
			$this->filter( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'host.docker.internal' )
		);
		$this->assertFalse( $this->filter( 'https_ssl_verify', true, 'https://host.docker.internal:9443/media/cover.jpg' ) );
	}

	public function test_other_hosts_are_left_to_wordpress(): void {
		DevHttp::allow( 'https://host.docker.internal:9443/api' );

		$this->assertFalse( $this->filter( 'http_request_host_is_external', false, '192.168.5.3' ) );
		$this->assertSame( array( 80, 443, 8080 ), $this->filter( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'localhost' ) );
		$this->assertTrue( $this->filter( 'https_ssl_verify', true, 'https://api.shorthand.com/media/cover.jpg' ) );
	}

	public function test_a_url_without_a_port_adds_none(): void {
		DevHttp::allow( 'https://api.shorthand.test/api' );

		$this->assertSame( array( 80, 443, 8080 ), $this->filter( 'http_allowed_safe_ports', array( 80, 443, 8080 ), 'api.shorthand.test' ) );
	}

	/**
	 * @dataProvider unusable_urls
	 */
	public function test_an_unusable_url_registers_nothing( string $api_url ): void {
		DevHttp::allow( $api_url );

		foreach ( self::HOOKS as $hook ) {
			$this->assertSame( array(), \tests_wp_hook_callbacks( $hook ), $hook );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unusable_urls(): array {
		return array(
			'empty'   => array( '' ),
			'no host' => array( '/api' ),
		);
	}

	/**
	 * `THESHED_NO_SSL_VERIFY` is not defined in the test process, so this is
	 * the production path.
	 */
	public function test_register_is_a_no_op_outside_local_development(): void {
		$this->assertFalse( defined( 'THESHED_NO_SSL_VERIFY' ) );

		DevHttp::register();

		foreach ( self::HOOKS as $hook ) {
			$this->assertSame( array(), \tests_wp_hook_callbacks( $hook ), $hook );
		}
	}

	/**
	 * Runs the single registered callback for a hook.
	 *
	 * @param mixed $value First filter argument.
	 * @param mixed $arg  Second filter argument.
	 * @return mixed
	 */
	private function filter( string $hook, $value, $arg ) {
		$hooks = \tests_wp_hook_callbacks( $hook );
		$this->assertCount( 1, $hooks, $hook );
		$this->assertSame( 2, $hooks[0]['accepted_args'], $hook );

		return $hooks[0]['callback']( $value, $arg );
	}
}
