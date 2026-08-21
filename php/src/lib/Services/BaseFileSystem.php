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

	public function __construct() {
		FileSystem::init();
	}

	/**
	 * Creates a staging directory under the local temporary directory.
	 *
	 * @param string $prefix Prefix for the directory name.
	 * @return string Absolute path to the new directory.
	 */
	public function make_temp_dir( string $prefix ): string {
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
		global $wp_filesystem;

		if ( ! $wp_filesystem->is_dir( $path ) ) {
			return true;
		}

		return $wp_filesystem->delete( $path, true );
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
			if ( ! FileSystem::concat_file( $part, $dest ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Copies a staged tree into the bundle directory, skipping unchanged files.
	 *
	 * @param string     $source_dir Staging directory to copy from.
	 * @param string     $dest_dir   Bundle directory to copy into.
	 * @param array|null $manifest   Manifest of the last successful copy, or null to copy everything.
	 * @return array|\WP_Error Manifest of the tree as copied, or an error.
	 */
	public function copy_tree( string $source_dir, string $dest_dir, ?array $manifest ) {
		$new_manifest = array();

		foreach ( $this->list_staged_files( $source_dir ) as $relative_path ) {
			$source_path = $source_dir . '/' . $relative_path;
			$entry       = $this->describe_file( $source_path );

			$new_manifest[ $relative_path ] = $entry;

			if ( $this->is_unchanged( $manifest, $relative_path, $entry ) ) {
				continue;
			}

			$dest_path = $dest_dir . '/' . $relative_path;

			if ( ! $this->make_dir( dirname( $dest_path ) ) ) {
				return new WP_Error( 'file', "Could not create the bundle directory {$dest_path}.", $dest_path );
			}

			$written = $this->write_file( $source_path, $dest_path );
			if ( is_wp_error( $written ) ) {
				return $written;
			}

			if ( ! $written ) {
				return new WP_Error( 'file', "Could not write the story file {$dest_path}.", $dest_path );
			}
		}

		return $new_manifest;
	}

	/**
	 * Deletes one file.
	 *
	 * @param string $path File to delete.
	 * @return bool True when the file is gone.
	 */
	public function delete_file( string $path ): bool {
		global $wp_filesystem;

		return $wp_filesystem->delete( $path, false, 'f' );
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
		global $wp_filesystem;

		return $wp_filesystem->copy( $source_path, $dest_path, true );
	}

	/**
	 * Lists a staged tree, as paths relative to `$source_dir`.
	 *
	 * The staging directory is local on every host, which is why this can
	 * enumerate at all. Nothing here is ever pointed at uploads.
	 *
	 * @param string $source_dir Staging directory to list.
	 * @return string[] Relative paths, sorted.
	 */
	protected function list_staged_files( string $source_dir ): array {
		if ( ! is_dir( $source_dir ) ) {
			return array();
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$paths  = array();
		$offset = strlen( $source_dir ) + 1;

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$paths[] = substr( $file->getPathname(), $offset );
			}
		}

		sort( $paths );

		return $paths;
	}

	/**
	 * Size and CRC32 of one staged file.
	 *
	 * CRC-32 here is the same checksum `ZipArchive::statIndex()` reports, so a
	 * manifest built either way compares equal.
	 *
	 * @param string $path Staged file to describe.
	 * @return array{size: int, crc: int} Manifest entry for the file.
	 */
	protected function describe_file( string $path ): array {
		return array(
			'size' => (int) filesize( $path ),
			'crc'  => (int) hexdec( hash_file( 'crc32b', $path ) ),
		);
	}

	/**
	 * Reports whether a staged file matches the manifest entry recorded for it.
	 *
	 * @param array|null                 $manifest      Manifest of the last successful copy, or null.
	 * @param string                     $relative_path Path of the file within the tree.
	 * @param array{size: int, crc: int} $entry         Manifest entry describing the staged file.
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
