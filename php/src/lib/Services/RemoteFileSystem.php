<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

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

	/**
	 * Copies one file into uploads, naming a refusal the host will not name.
	 *
	 * @param string $source_path Staged file to read.
	 * @param string $dest_path   Bundle path to write.
	 * @return bool|\WP_Error True on success, false on a plain failure, or an error the host named.
	 */
	protected function write_file( string $source_path, string $dest_path ) {
		$before = error_get_last();

		$written = parent::write_file( $source_path, $dest_path );

		if ( false !== $written ) {
			return $written;
		}

		if ( ! $this->is_write_cap_refusal( $before ) ) {
			return $written;
		}

		$error = new WP_Error( 'file', "The uploads host will accept no further writes to {$dest_path}.", $dest_path );
		$error->add(
			'pretty',
			__( 'This story can no longer be updated. Please contact Shorthand support.', 'the-shorthand-editor' )
		);

		return $error;
	}

	/**
	 * Reports whether a failed write was refused for exceeding the write cap.
	 *
	 * The uploads host permits a fixed number of modifications to any one
	 * path, and answers the next write with HTTP 405. That status does not
	 * reach a caller as a code: the host's API client has no branch for it,
	 * and returns a generic failure with the status embedded in the message
	 * as `(response code: 405)`. The stream wrapper then raises that message
	 * as a PHP warning, which is what this reads.
	 *
	 * Matching on message text is fragile, and this is the only place that
	 * does it. When the match fails, the plain write failure surfaces exactly
	 * as it did before.
	 *
	 * @param array|null $before Last PHP error before the write was attempted.
	 * @return bool True when the write was refused for exceeding the cap.
	 */
	private function is_write_cap_refusal( ?array $before ): bool {
		$last = error_get_last();

		if ( null === $last || $last === $before ) {
			return false;
		}

		return 1 === preg_match( '/\(\s*response code:\s*405\s*\)/i', (string) $last['message'] );
	}
}
