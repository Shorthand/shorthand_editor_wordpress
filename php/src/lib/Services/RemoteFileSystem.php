<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The uploads directory is an object store behind a PHP stream wrapper.
 *
 * Two constraints shape this implementation. Nothing can be enumerated, so a
 * tree can only be deleted through a manifest. Every write and every delete is
 * an HTTP round trip, so `copy_tree()` skipping unchanged files is what makes
 * a republish affordable.
 *
 * Nothing here calls `scandir()`, `glob()`, `opendir()`, `list_files()`,
 * `rmdir()`, or `WP_Filesystem::delete()` with `$recursive` set.
 */
class RemoteFileSystem extends BaseFileSystem {

	/**
	 * An object store has no directories, so there is nothing to remove.
	 *
	 * The keys of the objects it holds contain slashes; a directory is only
	 * ever implied by them.
	 *
	 * @param string $path Directory that would be removed on a local host.
	 * @return bool Always true.
	 */
	public function delete_dir( string $path ): bool {
		return true;
	}

	/**
	 * Unsupported: the contents of `$path` cannot be listed.
	 *
	 * Callers hold a manifest of what they wrote, and pass it to
	 * `delete_manifest()` instead.
	 *
	 * @param string $path Directory that cannot be enumerated.
	 * @return bool Always false.
	 */
	public function delete_tree( string $path ): bool {
		return false;
	}
}
