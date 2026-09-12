<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The uploads directory, as the story publish pipeline is allowed to use it.
 *
 * Four operations, and no way to ask what is there. That is the whole point:
 * on WordPress VIP the uploads directory is an object store behind a stream
 * wrapper, where nothing can be listed and every call is an HTTP round trip.
 * A surface that cannot enumerate behaves the same on every host, so there is
 * one implementation of this interface rather than one per host.
 *
 * What a caller may rely on:
 *
 * - `write()` puts a local file into uploads, overwriting.
 * - `read_into()` appends a file held in uploads to a local file. This is the
 *   only read, and downloaded chunks are the only thing it reads.
 * - `delete()` removes one named file.
 * - `make_dir()` may do nothing at all. An object store has no directories;
 *   the slashes in a key only imply them.
 *
 * See `docs/services/file-system.md`.
 */
interface Uploads {

	/**
	 * Copies a local file into uploads, replacing whatever is there.
	 *
	 * The return type is left undeclared because a write the host refuses is
	 * reported as a `\WP_Error`, and this plugin supports PHP versions without
	 * union return types.
	 *
	 * @param string $source_path Absolute path of a local file.
	 * @param string $dest_path   Absolute path in uploads.
	 * @return bool|\WP_Error True on success, false on failure, or an error the host named.
	 */
	public function write( string $source_path, string $dest_path );

	/**
	 * Appends a file held in uploads to a local file.
	 *
	 * @param string $path       Absolute path in uploads.
	 * @param string $local_path Absolute local path to append to, created if absent.
	 */
	public function read_into( string $path, string $local_path ): bool;

	/**
	 * Deletes one file from uploads.
	 *
	 * @param string $path Absolute path in uploads.
	 */
	public function delete( string $path ): bool;

	/**
	 * Creates a directory in uploads, and any missing parent.
	 *
	 * Reports success on a host that has no directories to create.
	 *
	 * @param string $path Absolute path in uploads.
	 */
	public function make_dir( string $path ): bool;
}
