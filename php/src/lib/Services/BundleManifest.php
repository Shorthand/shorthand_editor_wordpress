<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ZipArchive;

/**
 * Describes the files of one story bundle: name, size and CRC32 for each.
 *
 * A manifest is the only record of what a bundle directory holds. Nothing in
 * an object store can be enumerated, so both skipping unchanged files and
 * deleting stale ones are driven from here.
 *
 * CRC32 with size detects change between two exports of one story. It is not
 * a security boundary.
 */
class BundleManifest {

	/**
	 * The two files rewritten on every publish.
	 */
	const DOCUMENTS = array( 'article.html', 'head.html' );

	/**
	 * Reads an archive index, without extracting anything.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return array<string, array{size: int, crc: int}> Entry name to size and CRC32.
	 */
	public static function from_archive( ZipArchive $zip ): array {
		$manifest = array();

		for ( $idx = 0; $idx < $zip->numFiles; $idx++ ) {
			$stat = $zip->statIndex( $idx );

			if ( false === $stat || self::is_directory_entry( $stat['name'] ) ) {
				continue;
			}

			$manifest[ $stat['name'] ] = array(
				'size' => (int) $stat['size'],
				'crc'  => (int) $stat['crc'],
			);
		}

		ksort( $manifest );

		return $manifest;
	}

	/**
	 * The entries of `$stored` that `$current` no longer has.
	 *
	 * @param array $stored  Manifest of the last successful copy.
	 * @param array $current Manifest of the copy just made.
	 * @return array Entries to delete from the bundle directory.
	 */
	public static function removed( array $stored, array $current ): array {
		return array_diff_key( $stored, $current );
	}

	/**
	 * Reads a manifest out of post meta, tolerating anything unexpected there.
	 *
	 * @param mixed $value Stored meta value.
	 * @return array<string, array{size: int, crc: int}>
	 */
	public static function from_meta( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$manifest = array();

		foreach ( $value as $name => $entry ) {
			if ( is_array( $entry ) && isset( $entry['size'], $entry['crc'] ) ) {
				$manifest[ (string) $name ] = array(
					'size' => (int) $entry['size'],
					'crc'  => (int) $entry['crc'],
				);
			}
		}

		return $manifest;
	}

	/**
	 * Whether an archive entry is a directory rather than a file.
	 *
	 * @param string $name Archive entry name.
	 */
	private static function is_directory_entry( string $name ): bool {
		return '' === $name || '/' === substr( $name, -1 );
	}

	/**
	 * Moves the story documents under a per-publish directory.
	 *
	 * The bundle path is fixed for the life of a post, so `article.html` and
	 * `head.html` would otherwise be rewritten in place on every publish, and
	 * a host that caps modifications per path would eventually refuse them.
	 *
	 * The entry keeps a `from` key naming where the file was unpacked, which
	 * is what `Shorthand\Services\FileSystemService::copy_tree()` reads from.
	 * The previous directory is removed by the next publish's manifest diff.
	 *
	 * @param array  $manifest Manifest read out of the archive index.
	 * @param string $prefix   Directory to move the documents into, relative to the bundle.
	 * @return array<string, array{size: int, crc: int, from?: string}>
	 */
	public static function relocate_documents( array $manifest, string $prefix ): array {
		foreach ( self::DOCUMENTS as $name ) {
			if ( ! isset( $manifest[ $name ] ) ) {
				continue;
			}

			$entry         = $manifest[ $name ];
			$entry['from'] = $name;

			unset( $manifest[ $name ] );

			$manifest[ $prefix . '/' . $name ] = $entry;
		}

		ksort( $manifest );

		return $manifest;
	}
}
