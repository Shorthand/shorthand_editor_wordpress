<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File system operations used by the story publish pipeline.
 *
 * Two implementations exist, chosen by `FileSystem::create()`: one for a
 * uploads directory that is a plain path, one for an uploads directory that is
 * an object store behind a PHP stream wrapper. See
 * `docs/adr/0001-detect-remote-uploads-by-scheme.md`.
 *
 * Methods divide by which store they act on:
 *
 * - `make_temp_dir()` and `delete_temp_dir()` act on the staging directory,
 *   which is local on every host.
 * - Everything else acts on the uploads directory, which may be remote.
 */
interface FileSystemService {

	/**
	 * Creates an empty staging directory under the local temp directory.
	 *
	 * @param string $prefix Prefix for the directory name.
	 * @return string Absolute path, without a trailing slash.
	 */
	public function make_temp_dir( string $prefix ): string;

	/**
	 * Deletes a staging directory and everything in it.
	 *
	 * @param string $path Absolute path returned by `make_temp_dir()`.
	 */
	public function delete_temp_dir( string $path ): bool;

	/**
	 * Creates a directory in uploads, and any missing parent.
	 *
	 * @param string $path Absolute path.
	 */
	public function make_dir( string $path ): bool;

	/**
	 * Concatenates files into one, in the order given.
	 *
	 * @param string[] $parts Absolute paths of the pieces.
	 * @param string   $dest  Absolute path to write.
	 */
	public function join_pieces( array $parts, string $dest ): bool;

	/**
	 * Copies a local directory tree into uploads, skipping unchanged files.
	 *
	 * A file is unchanged when its name, size and CRC32 all match the entry in
	 * the supplied manifest. An absent manifest copies every file.
	 *
	 * The return type is left undeclared because a write refused by the host
	 * is reported as a `\WP_Error`, and this plugin supports PHP versions
	 * without union return types.
	 *
	 * @param string     $source_dir Absolute path of a local directory.
	 * @param string     $dest_dir   Absolute path in uploads.
	 * @param array|null $manifest   Manifest describing the current contents of `$dest_dir`.
	 * @return array|\WP_Error Manifest describing the new contents of `$dest_dir`.
	 */
	public function copy_tree( string $source_dir, string $dest_dir, ?array $manifest );

	/**
	 * Deletes one file from uploads.
	 *
	 * @param string $path Absolute path.
	 */
	public function delete_file( string $path ): bool;

	/**
	 * Deletes every file a manifest names, relative to a directory in uploads.
	 *
	 * This is the only way to empty a directory on a host that cannot
	 * enumerate one.
	 *
	 * @param string $dest_dir Absolute path in uploads.
	 * @param array  $manifest Manifest whose keys are paths relative to `$dest_dir`.
	 */
	public function delete_manifest( string $dest_dir, array $manifest ): bool;

	/**
	 * Removes an empty directory from uploads.
	 *
	 * An object store has no directories to remove, so the remote
	 * implementation reports success without acting.
	 *
	 * @param string $path Absolute path.
	 */
	public function delete_dir( string $path ): bool;

	/**
	 * Deletes a directory in uploads and everything below it.
	 *
	 * Only available where the uploads directory can be enumerated. The remote
	 * implementation returns false without acting; delete what a manifest
	 * names instead.
	 *
	 * @param string $path Absolute path.
	 */
	public function delete_tree( string $path ): bool;
}
