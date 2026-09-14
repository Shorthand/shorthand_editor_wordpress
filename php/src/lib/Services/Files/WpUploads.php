<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * The uploads directory, reached through `WP_Filesystem`.
 *
 * One implementation serves every host. WordPress swaps its own
 * `WP_Filesystem` for the uploads directory it has, and this class never asks
 * which one it got: it neither enumerates nor deletes recursively, so the same
 * calls mean the same thing on a plain directory and on an object store.
 *
 * Nothing here calls `scandir()`, `glob()`, `opendir()`, `list_files()`,
 * `rmdir()`, or `WP_Filesystem::delete()` with `$recursive` set.
 */
class WpUploads implements Uploads {

	/**
	 * Copies a local file into uploads, naming a refusal the host will not name.
	 *
	 * @param string $source_path Local file to read.
	 * @param string $dest_path   Path in uploads to write.
	 * @return bool|\WP_Error True on success, false on a plain failure, or an error the host named.
	 */
	public function write( string $source_path, string $dest_path ) {
		$file_system = FileSystem::boot();

		if ( null === $file_system ) {
			return new WP_Error( 'file', "No file system is available to write {$dest_path}.", $dest_path );
		}

		$refusals = self::count_upload_refusals( $file_system );

		$written = $file_system->copy( $source_path, $dest_path, true );

		if ( false !== $written || ! self::is_write_cap_refusal( $file_system, $refusals ) ) {
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
	 * Appends a file held in uploads to a local file.
	 *
	 * `WP_Filesystem::put_contents()` has no append mode, and the destination
	 * is the local staging directory, so the append is a plain one.
	 *
	 * @param string $path       File in uploads to read.
	 * @param string $local_path Local file to append it to.
	 * @return bool True when the whole file was appended.
	 */
	public function read_into( string $path, string $local_path ): bool {
		$file_system = FileSystem::boot();

		if ( null === $file_system ) {
			return false;
		}

		$contents = $file_system->get_contents( $path );

		if ( false === $contents ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The destination is the local staging directory, and WP_Filesystem::put_contents() does not append.
		return file_put_contents( $local_path, $contents, FILE_APPEND ) === strlen( $contents );
	}

	/**
	 * Deletes one file from uploads.
	 *
	 * @param string $path File to delete.
	 * @return bool True when the file is gone.
	 */
	public function delete( string $path ): bool {
		$file_system = FileSystem::boot();

		return null !== $file_system && $file_system->delete( $path, false, 'f' );
	}

	/**
	 * Creates a directory, and any missing parent of it.
	 *
	 * On an object store this creates nothing and reports success, which is
	 * what the callers of this interface are written to expect.
	 *
	 * @param string $path Directory to create.
	 * @return bool True on success.
	 */
	public function make_dir( string $path ): bool {
		return wp_mkdir_p( $path );
	}

	/**
	 * Reports whether the write that just failed was refused for exceeding the cap.
	 *
	 * WordPress VIP permits a fixed number of modifications to any one path
	 * and refuses the next write. The status does not reach a caller as a
	 * code: the host's API client has no branch for it, and returns a generic
	 * `upload_file-failed` error with the status embedded in the message as
	 * `(response code: 405)`. `WP_Filesystem::copy()` leaves that error on its
	 * `errors` property and answers false, which is what this reads.
	 *
	 * The 2000-modification limit is documented; the status code is not, and
	 * matching on message text is fragile. This is the only place in this
	 * codebase that does either, and it runs on every host, because only a
	 * host with the cap can produce the error it matches. When the match
	 * fails, the plain write failure surfaces untouched.
	 *
	 * `errors` is never cleared and the file system is booted once per
	 * request, so only the messages the write itself added are read: whatever
	 * stood before it belongs to an earlier call.
	 *
	 * @link https://docs.wpvip.com/vip-file-system/media-uploads/
	 *       The 2000-modification limit.
	 * @link https://github.com/Automattic/vip-go-mu-plugins/blob/35ff0ddaa1d996d1adcff99e0fff35d59d051db7/files/class-api-client.php#L144
	 *       `Api_Client::upload_file()` building the message.
	 * @link https://github.com/Automattic/vip-go-mu-plugins/blob/968d6196fe98dfd570e09a6271f34b2bb84d085e/files/class-wp-filesystem-vip.php#L243-L247
	 *       `WP_Filesystem_VIP::copy()` leaving it on `errors`.
	 *
	 * @param \WP_Filesystem_Base $file_system File system the write went through.
	 * @param int                 $refusals    Refusals already on it before the write.
	 * @return bool True when the write was refused for exceeding the cap.
	 */
	private static function is_write_cap_refusal( $file_system, int $refusals ): bool {
		$messages = array_slice( self::upload_refusals( $file_system ), $refusals );

		foreach ( $messages as $message ) {
			if ( 1 === preg_match( '/\(\s*response code:\s*405\s*\)/i', $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many upload failures stand before a write is attempted.
	 *
	 * @param \WP_Filesystem_Base $file_system File system to read.
	 */
	private static function count_upload_refusals( $file_system ): int {
		return count( self::upload_refusals( $file_system ) );
	}

	/**
	 * Every upload failure the file system has recorded this request, in order.
	 *
	 * @param \WP_Filesystem_Base $file_system File system to read.
	 * @return array<int, string>
	 */
	private static function upload_refusals( $file_system ): array {
		$errors = $file_system->errors;

		if ( ! is_wp_error( $errors ) || ! isset( $errors->errors['upload_file-failed'] ) ) {
			return array();
		}

		return $errors->errors['upload_file-failed'];
	}
}
