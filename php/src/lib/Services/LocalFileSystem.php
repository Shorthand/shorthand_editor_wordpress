<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The uploads directory is a plain path on the web server's own file system.
 *
 * Directories can be enumerated and removed, so a tree can be deleted without
 * a manifest.
 */
class LocalFileSystem extends BaseFileSystem {

	public function delete_dir( string $path ): bool {
		global $wp_filesystem;

		if ( ! $wp_filesystem->is_dir( $path ) ) {
			return true;
		}

		return $wp_filesystem->rmdir( $path );
	}

	public function delete_tree( string $path ): bool {
		global $wp_filesystem;

		if ( ! $wp_filesystem->exists( $path ) ) {
			return true;
		}

		return $wp_filesystem->delete( $path, true );
	}
}
