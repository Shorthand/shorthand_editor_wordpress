<?php

declare(strict_types=1);

namespace Shorthand\Tests;

/**
 * Covers the hook stubs in tests/bootstrap.php, which several suites rely on
 * to reason about ordering.
 */
final class HookStubTest extends WordPressTestCase {

	public function test_actions_run_in_priority_order(): void {
		$calls = array();

		\add_action( 'shorthand_test_hook', $this->recorder( $calls, 'late' ), 20 );
		\add_action( 'shorthand_test_hook', $this->recorder( $calls, 'early' ), 5 );
		\add_action( 'shorthand_test_hook', $this->recorder( $calls, 'default' ) );

		\do_action( 'shorthand_test_hook' );

		$this->assertSame( array( 'early', 'default', 'late' ), $calls );
	}

	public function test_actions_sharing_a_priority_run_in_registration_order(): void {
		$calls = array();

		\add_action( 'shorthand_test_hook', $this->recorder( $calls, 'first' ), 10 );
		\add_action( 'shorthand_test_hook', $this->recorder( $calls, 'second' ), 10 );
		\add_action( 'shorthand_test_hook', $this->recorder( $calls, 'third' ), 10 );

		\do_action( 'shorthand_test_hook' );

		$this->assertSame( array( 'first', 'second', 'third' ), $calls );
	}

	public function test_a_callback_sees_only_the_arguments_it_accepts(): void {
		$seen = array();

		\add_action(
			'shorthand_test_hook',
			static function ( ...$args ) use ( &$seen ): void {
				$seen[] = $args;
			},
			10,
			2
		);

		\do_action( 'shorthand_test_hook', 'a', 'b', 'c' );

		$this->assertSame( array( array( 'a', 'b' ) ), $seen );
	}

	/**
	 * @param array<int, string> $calls Collected in call order.
	 * @return callable
	 */
	private function recorder( array &$calls, string $name ): callable {
		return static function () use ( &$calls, $name ): void {
			$calls[] = $name;
		};
	}
}
