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
	 * The booted `WP_Filesystem`.
	 *
	 * @return \WP_Filesystem_Base
	 */
	public static function boot() {
		if ( self::$booted ) {
			return $GLOBALS['wp_filesystem'];
		}

		wp_raise_memory_limit( 'admin' );

		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();

		self::$booted = true;

		if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! is_a( $GLOBALS['wp_filesystem'], 'WP_Filesystem_Base' ) ) {
			WP_Filesystem( request_filesystem_credentials( site_url() ) );
		}

		return $GLOBALS['wp_filesystem'];
	}
}
