<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets only one step of a post's pull run at a time.
 *
 * Two runs of one step can overlap: two cron runners can pick the same
 * event, and the watch can resume a step that was only slow. The lock is a
 * row in the options table, taken with `INSERT IGNORE` as in
 * `WP_Upgrader::create_lock()`. Its value is the time it expires, so a lock
 * left by a killed step is taken over later.
 *
 * Every read and write is direct SQL. The options API caches values, and a
 * cached copy would not see another request take or release the lock.
 */
class StoryPullLock {

	/**
	 * Option name prefix; the post ID follows.
	 */
	const OPTION_PREFIX = 'shorthand_pull_lock_';

	/**
	 * Takes the lock for a post, or takes over one that expired.
	 *
	 * @param int $post_id Post being published.
	 * @param int $seconds How long the lock holds before another step may take it over.
	 * @return int|null The lock's expiry time, which `release()` needs; null when another step holds it.
	 */
	public function acquire( int $post_id, int $seconds ): ?int {
		global $wpdb;

		/* This is the step's first query. An earlier event in the same cron run may have broken the connection. */
		$wpdb->check_connection( false );

		$name   = self::OPTION_PREFIX . $post_id;
		$now    = time();
		$expiry = (string) ( $now + $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic insert is the lock; the options API cannot do it.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' )", $name, $expiry ) );
		if ( 1 === $taken ) {
			return (int) $expiry;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must read the row, not a cached copy.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name ) );
		if ( null === $held || (int) $held > $now ) {
			return null;
		}

		// Only one of two steps that found the same expired value can change it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set on the lock row.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", $expiry, $name, $held ) );

		return 1 === $taken ? (int) $expiry : null;
	}

	/**
	 * Releases the lock, unless another step took it over after it expired.
	 *
	 * @param int $post_id Post being published.
	 * @param int $expiry  Value `acquire()` returned.
	 */
	public function release( int $post_id, int $expiry ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only this step's lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s", self::OPTION_PREFIX . $post_id, (string) $expiry ) );
	}
}
