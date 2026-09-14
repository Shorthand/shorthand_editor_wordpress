<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use ReflectionClass;
use ReflectionMethod;
use Shorthand\Services\ConnectionFailure;
use Shorthand\Tests\WordPressTestCase;

final class ConnectionFailureTest extends WordPressTestCase {

	/**
	 * Every named constructor, so a new mode is covered without being listed.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function every_mode(): array {
		$modes = array();

		foreach ( ( new ReflectionClass( ConnectionFailure::class ) )->getMethods( ReflectionMethod::IS_STATIC ) as $method ) {
			$returns = $method->getReturnType();
			if ( ! $method->isPublic() || 0 !== $method->getNumberOfParameters() || null === $returns ) {
				continue;
			}

			if ( ConnectionFailure::class === $returns->getName() ) {
				$modes[ $method->getName() ] = array( $method->getName() );
			}
		}

		return $modes;
	}

	/**
	 * A 5xx page is replaced by the host's CDN with its own error page, so
	 * the copy never reaches the reader (PLA-2804). Upstream failure is not
	 * failure of the browser-to-WordPress exchange, which succeeded.
	 *
	 * @dataProvider every_mode
	 */
	public function test_no_failure_mode_is_served_as_a_server_error( string $mode ): void {
		$status = ConnectionFailure::{$mode}()->get_status();

		$this->assertLessThan( 500, $status, "{$mode} is served as {$status}; a CDN would discard the page." );
	}

	/**
	 * @dataProvider every_mode
	 */
	public function test_every_mode_carries_a_stable_slug_and_actionable_copy( string $mode ): void {
		$failure = ConnectionFailure::{$mode}();

		$this->assertStringStartsWith( 'connect.', $failure->get_slug() );
		$this->assertNotSame( '', $failure->get_title() );
		$this->assertNotSame( '', $failure->get_message() );
		$this->assertNotSame( '', $failure->get_advice() );
		$this->assertNotEmpty( $failure->get_actions(), 'An error page must never be a dead end.' );
	}

	/**
	 * Only a fault in the request WordPress received earns a non-2xx.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function local_fault_modes(): array {
		return array(
			'no permission to start'  => array( 'permission_to_connect', 403 ),
			'no permission to finish' => array( 'permission_to_complete', 403 ),
			'stale nonce'             => array( 'expired', 400 ),
			'damaged return token'    => array( 'return_token_malformed', 400 ),
		);
	}

	/**
	 * @dataProvider local_fault_modes
	 */
	public function test_a_fault_in_the_incoming_request_keeps_its_status( string $mode, int $expected ): void {
		$this->assertSame( $expected, ConnectionFailure::{$mode}()->get_status() );
	}
}
