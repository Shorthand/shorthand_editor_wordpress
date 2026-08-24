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
	 * Captures the scheme of a path that names a PHP stream wrapper.
	 *
	 * See `docs/services/file-system.md`. The test is on
	 * the shape of the path, never on a vendor constant or a named plugin.
	 */
	const REMOTE_SCHEME_PATTERN = '#^([a-z][a-z0-9+.\\-]*)://#i';

	/**
	 * Whether `WP_Filesystem` has been booted for this request.
	 *
	 * @var bool
	 */
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
		return '' !== self::get_uploads_scheme();
	}

	/**
	 * Lowercased stream wrapper scheme of the uploads directory.
	 *
	 * @return string Scheme such as `vip`, or an empty string where uploads are a plain path.
	 */
	public static function get_uploads_scheme(): string {
		$basedir = wp_upload_dir()['basedir'];

		if ( ! is_string( $basedir ) || ! preg_match( self::REMOTE_SCHEME_PATTERN, $basedir, $matches ) ) {
			return '';
		}

		return strtolower( $matches[1] );
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
}
