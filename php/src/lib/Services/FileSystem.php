<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileSystem {

	private static $has_init_fs = false;

	public static function init() {
		if ( self::$has_init_fs ) {
			return;
		}

		wp_raise_memory_limit( 'admin' );

		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();
		global $wp_filesystem;
		self::$has_init_fs = true;
		if ( ! is_a( $wp_filesystem, 'WP_Filesystem_Base' ) ) {
			$creds = request_filesystem_credentials( site_url() );
			wp_filesystem( $creds );
		}
	}

	public static function concat_file( string $source_path, string $dest_path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem::put_contents() does not support FILE_APPEND
		$source_contents = file_get_contents( $source_path );
		if ( false === $source_contents ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem::put_contents() does not support FILE_APPEND
		return file_put_contents( $dest_path, $source_contents, FILE_APPEND ) === strlen( $source_contents );
	}
}
