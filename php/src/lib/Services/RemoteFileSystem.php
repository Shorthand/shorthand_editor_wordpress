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
		$written = parent::write_file( $source_path, $dest_path );

		if ( false !== $written || ! $this->is_write_cap_refusal() ) {
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
	 * Reports whether the write that just failed was refused for exceeding the cap.
	 *
	 * The uploads host permits a fixed number of modifications to any one
	 * path and refuses the next write. The status does not reach a caller as
	 * a code: the host's API client has no branch for it, and returns a
	 * generic `upload_file-failed` error with the status embedded in the
	 * message as `(response code: 405)`. `WP_Filesystem::copy()` leaves that
	 * error on its `errors` property and answers false, which is what this
	 * reads.
	 *
	 * The 2000-modification limit is documented; the status code is not, and
	 * matching on message text is fragile. This is the only place in this
	 * codebase that does either. When the match fails, the plain write
	 * failure surfaces exactly as it did before.
	 *
	 * The error code is checked as well as the text, because `errors` is not
	 * cleared between calls. Only the upload branch sets that code.
	 *
	 * @link https://docs.wpvip.com/vip-file-system/media-uploads/
	 *       The 2000-modification limit.
	 * @link https://github.com/Automattic/vip-go-mu-plugins/blob/35ff0ddaa1d996d1adcff99e0fff35d59d051db7/files/class-api-client.php#L144
	 *       `Api_Client::upload_file()` building the message.
	 * @link https://github.com/Automattic/vip-go-mu-plugins/blob/968d6196fe98dfd570e09a6271f34b2bb84d085e/files/class-wp-filesystem-vip.php#L243-L247
	 *       `WP_Filesystem_VIP::copy()` leaving it on `errors`.
	 *
	 * @return bool True when the write was refused for exceeding the cap.
	 */
	private function is_write_cap_refusal(): bool {
		$errors = $this->wp_filesystem()->errors;

		if ( ! is_wp_error( $errors ) || 'upload_file-failed' !== $errors->get_error_code() ) {
			return false;
		}

		return 1 === preg_match( '/\(\s*response code:\s*405\s*\)/i', $errors->get_error_message() );
	}
}
