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
	 * Whether a boot has been tried for this request, whatever its result.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * The booted `WP_Filesystem`, or null where there is none.
	 *
	 * A boot can fail: credentials may be missing, and `WP_Filesystem()` then
	 * leaves the global unset. A failed boot is remembered too. Credentials
	 * arrive with a new request, never part way through this one, so a second
	 * try would only print the credentials form again.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function boot(): ?\WP_Filesystem_Base {
		if ( ! self::$booted ) {
			self::$booted = true;

			wp_raise_memory_limit( 'admin' );

			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();

			if ( ! self::is_booted() ) {
				WP_Filesystem( request_filesystem_credentials( site_url() ) );
			}
		}

		return self::is_booted() ? $GLOBALS['wp_filesystem'] : null;
	}

	/**
	 * Whether the `WP_Filesystem` global holds a usable instance.
	 */
	private static function is_booted(): bool {
		return isset( $GLOBALS['wp_filesystem'] ) && is_a( $GLOBALS['wp_filesystem'], 'WP_Filesystem_Base' );
	}
}
