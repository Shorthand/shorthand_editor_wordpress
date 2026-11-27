<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryPullLock;
use Shorthand\Tests\WordPressTestCase;

/**
 * Renewing the per-post step lock while a step works.
 */
final class StoryPullLockTest extends WordPressTestCase {

	/**
	 * Step lock option of post 7.
	 */
	private const LOCK = 'shorthand_pull_lock_7';

	public function test_a_held_lock_is_renewed_from_now(): void {
		$lock   = new StoryPullLock();
		$expiry = $lock->acquire( 7, 10 );

		$renewed = $lock->renew( 7, $expiry, 900 );

		$this->assertGreaterThanOrEqual( time() + 899, $renewed );
		$this->assertSame( (string) $renewed, (string) get_option( self::LOCK ) );
	}

	/**
	 * A renew in the same second changes no row; the lock is still held.
	 */
	public function test_a_renew_that_changes_nothing_still_reports_the_lock_held(): void {
		$lock   = new StoryPullLock();
		$expiry = $lock->acquire( 7, 200 );

		$this->assertSame( $expiry, $lock->renew( 7, $expiry, 200 ) );
	}

	/**
	 * A step paused past its expiry finds the lock taken over, and stops.
	 */
	public function test_a_lock_another_request_took_over_is_not_renewed(): void {
		$lock   = new StoryPullLock();
		$expiry = $lock->acquire( 7, 200 );
		update_option( self::LOCK, (string) ( time() + 500 ) );

		$this->assertNull( $lock->renew( 7, $expiry, 200 ) );
		$this->assertSame( (string) ( time() + 500 ), (string) get_option( self::LOCK ) );
	}

	public function test_a_released_lock_is_not_renewed(): void {
		$lock   = new StoryPullLock();
		$expiry = $lock->acquire( 7, 200 );
		$lock->release( 7, $expiry );

		$this->assertNull( $lock->renew( 7, $expiry, 200 ) );
		$this->assertFalse( get_option( self::LOCK ) );
	}

	public function test_release_needs_the_renewed_expiry(): void {
		$lock    = new StoryPullLock();
		$expiry  = $lock->acquire( 7, 10 );
		$renewed = $lock->renew( 7, $expiry, 900 );

		$lock->release( 7, $expiry );
		$this->assertNotFalse( get_option( self::LOCK ), 'The old expiry no longer names the row' );

		$lock->release( 7, $renewed );
		$this->assertFalse( get_option( self::LOCK ) );
	}
}
