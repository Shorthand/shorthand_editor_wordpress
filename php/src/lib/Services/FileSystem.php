<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots `WP_Filesystem`, and chooses the file system service for this host.
 */
class FileSystem {

	/**
	 * Matches a path that names a PHP stream wrapper rather than a directory.
	 *
	 * See `docs/adr/0001-detect-remote-uploads-by-scheme.md`. The test is on
	 * the shape of the path, never on a vendor constant or a named plugin.
	 */
	const REMOTE_SCHEME_PATTERN = '#^[a-z][a-z0-9+.\\-]*://#i';

	private static $has_init_fs = false;

	/**
	 * The file system service for the uploads directory of this site.
	 */
	public static function create(): FileSystemService {
		return self::is_remote_uploads() ? new RemoteFileSystem() : new LocalFileSystem();
	}

	/**
	 * Reports whether uploads are held somewhere other than this file system.
	 */
	public static function is_remote_uploads(): bool {
		$basedir = wp_upload_dir()['basedir'];

		return is_string( $basedir ) && 1 === preg_match( self::REMOTE_SCHEME_PATTERN, $basedir );
	}

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
