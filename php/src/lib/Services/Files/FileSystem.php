<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots `WP_Filesystem`, once per request.
 *
 * Booting pulls in an admin include and raises the memory limit, so nothing
 * boots on construction. Every caller goes through here and gets the same
 * instance back.
 */
class FileSystem {

	/**
	 * Whether `WP_Filesystem` has been booted for this request.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * The booted `WP_Filesystem`, or null where there is none.
	 *
	 * A boot can fail: credentials may be missing, and `WP_Filesystem()` then
	 * leaves the global unset. Only a successful boot is remembered, so a
	 * later call tries again rather than answering with the unset global.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function boot(): ?\WP_Filesystem_Base {
		if ( self::$booted ) {
			return $GLOBALS['wp_filesystem'];
		}

		wp_raise_memory_limit( 'admin' );

		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();

		if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! is_a( $GLOBALS['wp_filesystem'], 'WP_Filesystem_Base' ) ) {
			WP_Filesystem( request_filesystem_credentials( site_url() ) );
		}

		if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! is_a( $GLOBALS['wp_filesystem'], 'WP_Filesystem_Base' ) ) {
			return null;
		}

		self::$booted = true;

		return $GLOBALS['wp_filesystem'];
	}
}
