<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * The file system behaviour that does not depend on the uploads host.
 *
 * Subclasses supply only what differs: removing directories, and reporting a
 * write the host refused.
 */
abstract class BaseFileSystem implements FileSystemService {

	/**
	 * `WP_Filesystem`, booted on first use.
	 *
	 * Booting requires an admin include, raises the memory limit, and may ask
	 * for credentials, so it waits until something is actually written.
	 *
	 * @return \WP_Filesystem_Base
	 */
	protected function wp_filesystem() {
		FileSystem::init();

		global $wp_filesystem;

		return $wp_filesystem;
	}

	/**
	 * Creates a staging directory under the local temporary directory.
	 *
	 * @param string $prefix Prefix for the directory name.
	 * @return string Absolute path to the new directory.
	 */
	public function make_temp_dir( string $prefix ): string {
		/* Every publish starts here, and unpacking an archive wants the admin memory limit. */
		FileSystem::init();

		$base = untrailingslashit( get_temp_dir() );

		do {
			$path = $base . '/' . $prefix . wp_rand( 100000, 999999 );
		} while ( file_exists( $path ) );

		wp_mkdir_p( $path );

		return $path;
	}

	/**
	 * Removes a staging directory and everything under it.
	 *
	 * The staging directory is local on every host, so this is a plain
	 * recursive delete on both implementations.
	 *
	 * @param string $path Staging directory to remove.
	 * @return bool True when the directory is gone.
	 */
	public function delete_temp_dir( string $path ): bool {
		$fs = $this->wp_filesystem();

		if ( ! $fs->is_dir( $path ) ) {
			return true;
		}

		return $fs->delete( $path, true );
	}

	/**
	 * Creates a directory, and any missing parent of it.
	 *
	 * @param string $path Directory to create.
	 * @return bool True on success.
	 */
	public function make_dir( string $path ): bool {
		return wp_mkdir_p( $path );
	}

	/**
	 * Concatenates downloaded chunks into one file, in order.
	 *
	 * @param string[] $parts Chunk paths, in the order they were downloaded.
	 * @param string   $dest  File to append them to.
	 * @return bool True when every chunk was appended.
	 */
	public function join_pieces( array $parts, string $dest ): bool {
		foreach ( $parts as $part ) {
			if ( ! $this->append_file( $part, $dest ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Appends one file to another, creating it if it is not there yet.
	 *
	 * Both paths are local: chunks are downloaded into the staging directory,
	 * where a plain append is available. `WP_Filesystem::put_contents()` has no
	 * append mode.
	 *
	 * @param string $source_path File to read.
	 * @param string $dest_path   File to append it to.
	 * @return bool True when the whole source was appended.
	 */
	private function append_file( string $source_path, string $dest_path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the staging directory is local, and WP_Filesystem::put_contents() does not append.
		$source_contents = file_get_contents( $source_path );
		if ( false === $source_contents ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem::put_contents() does not append.
		return file_put_contents( $dest_path, $source_contents, FILE_APPEND ) === strlen( $source_contents );
	}

	/**
	 * Copies a staged tree into the bundle directory, skipping unchanged files.
	 *
	 * Driven from `$source_manifest`, never by enumerating either directory.
	 *
	 * @param string     $source_dir      Staging directory to copy from.
	 * @param string     $dest_dir        Bundle directory to copy into.
	 * @param array      $source_manifest The tree to copy: bundle path to size, CRC32, and the staged name to read when it differs.
	 * @param array|null $dest_manifest   Manifest of the last successful copy, or null to copy everything.
	 * @return array|\WP_Error The manifest of the bundle as it now stands, or an error.
	 */
	public function copy_tree( string $source_dir, string $dest_dir, array $source_manifest, ?array $dest_manifest ) {
		$made = array();

		foreach ( $source_manifest as $relative_path => $entry ) {
			if ( $this->is_unchanged( $dest_manifest, $relative_path, $entry ) ) {
				continue;
			}

			$dest_path   = $dest_dir . '/' . $relative_path;
			$parent_path = dirname( $dest_path );

			if ( ! isset( $made[ $parent_path ] ) ) {
				if ( ! $this->make_dir( $parent_path ) ) {
					return new WP_Error( 'file', "Could not create the bundle directory {$parent_path}.", $parent_path );
				}

				$made[ $parent_path ] = true;
			}

			$source_path = $source_dir . '/' . ( isset( $entry['from'] ) ? $entry['from'] : $relative_path );

			$written = $this->write_file( $source_path, $dest_path );
			if ( is_wp_error( $written ) ) {
				return $written;
			}

			if ( ! $written ) {
				return new WP_Error( 'file', "Could not write the story file {$dest_path}.", $dest_path );
			}
		}

		return $this->without_sources( $source_manifest );
	}

	/**
	 * Drops the copy instructions, leaving a description of the bundle.
	 *
	 * @param array $manifest Manifest that drove the copy.
	 * @return array<string, array{size: int, crc: int}>
	 */
	private function without_sources( array $manifest ): array {
		foreach ( $manifest as $relative_path => $entry ) {
			if ( ! isset( $entry['from'] ) ) {
				continue;
			}

			unset( $entry['from'] );

			$manifest[ $relative_path ] = $entry;
		}

		return $manifest;
	}

	/**
	 * Deletes one file.
	 *
	 * @param string $path File to delete.
	 * @return bool True when the file is gone.
	 */
	public function delete_file( string $path ): bool {
		return $this->wp_filesystem()->delete( $path, false, 'f' );
	}

	/**
	 * Deletes every file a manifest names, without enumerating the directory.
	 *
	 * @param string $dest_dir Bundle directory the manifest describes.
	 * @param array  $manifest Manifest of the last successful copy.
	 * @return bool True when every named file is gone.
	 */
	public function delete_manifest( string $dest_dir, array $manifest ): bool {
		$deleted = true;

		foreach ( array_keys( $manifest ) as $relative_path ) {
			if ( ! $this->delete_file( $dest_dir . '/' . $relative_path ) ) {
				$deleted = false;
			}
		}

		return $deleted;
	}

	/**
	 * Copies one file into uploads.
	 *
	 * @param string $source_path Staged file to read.
	 * @param string $dest_path   Bundle path to write.
	 * @return bool|\WP_Error True on success, false on a plain failure, or an error the host named.
	 */
	protected function write_file( string $source_path, string $dest_path ) {
		return $this->wp_filesystem()->copy( $source_path, $dest_path, true );
	}

	/**
	 * Reports whether a staged file matches the manifest entry recorded for it.
	 *
	 * @param array|null                 $manifest      Manifest of the last successful copy, or null.
	 * @param string                     $relative_path Path of the file within the tree.
	 * @param array{size: int, crc: int} $entry         Source manifest entry for the file.
	 * @return bool True when the file can be skipped.
	 */
	private function is_unchanged( ?array $manifest, string $relative_path, array $entry ): bool {
		if ( null === $manifest || ! isset( $manifest[ $relative_path ] ) ) {
			return false;
		}

		$stored = $manifest[ $relative_path ];

		return isset( $stored['size'], $stored['crc'] )
			&& (int) $stored['size'] === $entry['size']
			&& (int) $stored['crc'] === $entry['crc'];
	}
}
