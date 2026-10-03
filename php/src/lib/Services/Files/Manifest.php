<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
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
class Manifest {

	/**
	 * The two story documents, which publishing stores as post meta.
	 */
	const DOCUMENTS = array( 'article.html', 'head.html' );

	/**
	 * Reads an archive index, without extracting anything.
	 *
	 * Each file is keyed by its plain name, without `.` or empty segments,
	 * because that is the file `ZipArchive::extractTo()` writes. Where two
	 * entries name one file, the later entry is the one left on disk, so the
	 * later entry is the one described. `collisions()` reports such names.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return array<string, array{size: int, crc: int}>|\WP_Error Bundle path to size and CRC32.
	 */
	public static function from_archive( ZipArchive $zip ) {
		$manifest = array();

		$count = $zip->count();

		for ( $idx = 0; $idx < $count; $idx++ ) {
			$stat = $zip->statIndex( $idx );

			if ( false === $stat ) {
				continue;
			}

			/* `Archive::unpack_to()` extracts directory entries too. */
			if ( ! self::is_safe_name( $stat['name'] ) ) {
				return self::get_unsafe_name_error( $stat['name'] );
			}

			if ( self::is_directory_entry( $stat['name'] ) ) {
				continue;
			}

			$name = self::canonical( $stat['name'] );

			if ( '' === $name ) {
				return self::get_unsafe_name_error( $stat['name'] );
			}

			$manifest[ $name ] = array(
				'size' => (int) $stat['size'],
				'crc'  => (int) $stat['crc'],
			);
		}

		ksort( $manifest );

		return $manifest;
	}

	/**
	 * Groups the file names that can be one file on some host.
	 *
	 * Two names are one file everywhere when they differ only by `.` or empty
	 * segments, and one file on a host that ignores case when they differ only
	 * in case. Either way one may show in place of the other. The story still
	 * publishes; the fault is in the export, and the author is told.
	 *
	 * @param string[] $names Archive entry names, in archive order.
	 * @return array<int, string[]> Each group of two or more names, in archive order.
	 */
	public static function collisions( array $names ): array {
		$groups = array();

		foreach ( $names as $name ) {
			if ( self::is_directory_entry( $name ) ) {
				continue;
			}

			$groups[ strtolower( self::canonical( $name ) ) ][] = $name;
		}

		$collisions = array();

		foreach ( $groups as $group ) {
			if ( count( $group ) > 1 ) {
				$collisions[] = $group;
			}
		}

		return $collisions;
	}

	/**
	 * Whether an entry name stays inside the bundle.
	 *
	 * Entry names become path segments the same way a story ID and a request
	 * nonce do, and unlike those two they are received rather than generated.
	 * A name that escapes fails the whole publish: skipping it would leave the
	 * bundle incomplete, and the manifest naming a file that is not on disk.
	 *
	 * A `.` or empty segment stays inside, and is dropped by `canonical()`.
	 *
	 * @param string $name Archive entry name.
	 */
	private static function is_safe_name( string $name ): bool {
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) ) {
			return false;
		}

		if ( '/' === $name[0] || 1 === preg_match( '/^[A-Za-z]:/', $name ) ) {
			return false;
		}

		return ! in_array( '..', explode( '/', $name ), true );
	}

	/**
	 * The name of the file an entry is written to.
	 *
	 * `ZipArchive::extractTo()` and a disk resolve `assets/./theme.css` and
	 * `assets//theme.css` to `assets/theme.css`.
	 *
	 * @param string $name Entry name that `is_safe_name()` accepts.
	 * @return string The name without `.` or empty segments; empty for the bundle itself.
	 */
	private static function canonical( string $name ): string {
		return implode( '/', array_diff( explode( '/', $name ), array( '', '.' ) ) );
	}

	/**
	 * The error that ends a publish over an entry name outside the bundle.
	 *
	 * @param string $name Archive entry name.
	 */
	private static function get_unsafe_name_error( string $name ): WP_Error {
		return new WP_Error( 'file', "The story archive names {$name}, which is not a plain path inside the bundle.", $name );
	}

	/**
	 * The entries of `$stored` that `$current` no longer has.
	 *
	 * A stored name that folds to a current one is kept. File names are
	 * case-insensitive on an object store, so a file renamed only in case is
	 * one file there, and deleting the old name would delete what the copy has
	 * just written. On a disk the two are two files, and the old one is left
	 * behind, named by no manifest: an orphan costs storage, where the wrong
	 * delete costs the story an asset and nothing reads a bundle back to
	 * notice.
	 *
	 * @param array $stored  Manifest of the last successful copy.
	 * @param array $current Manifest of the copy just made.
	 * @return array Entries to delete from the bundle directory.
	 */
	public static function removed( array $stored, array $current ): array {
		$gone = array_diff_key( $stored, $current );

		if ( empty( $gone ) ) {
			return $gone;
		}

		$folded = array();

		foreach ( array_keys( $current ) as $name ) {
			$folded[ strtolower( $name ) ] = true;
		}

		foreach ( array_keys( $gone ) as $name ) {
			if ( isset( $folded[ strtolower( $name ) ] ) ) {
				unset( $gone[ $name ] );
			}
		}

		return $gone;
	}

	/**
	 * Reads a manifest out of post meta, tolerating anything unexpected there.
	 *
	 * A manifest that was never written reads as empty: that is the first
	 * publish after an upgrade. An entry that cannot be read is different, and
	 * is reported: the file it names stays in uploads for good, because the
	 * manifest is the only record `prune()` and `delete()` have.
	 *
	 * A name is held to the rules an archive entry is, because `prune()` and
	 * `delete()` join it onto the bundle path. One that fails them is dropped
	 * and reported. One that passes is read back by its plain name, the way
	 * `from_archive()` keys it, so it is never pruned as a second file.
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
			$path = self::is_safe_name( (string) $name ) ? self::canonical( (string) $name ) : '';

			if ( '' === $path ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: name of a file as the story manifest stores it. */
						esc_html__( 'The stored story manifest names %s, which is not a plain path inside the story bundle, so that file is left alone.', 'the-shorthand-editor' ),
						esc_html( (string) $name )
					),
					'1.0.10'
				);

				continue;
			}

			if ( ! is_array( $entry ) || ! isset( $entry['size'], $entry['crc'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: path of the file inside the story bundle. */
						esc_html__( 'The stored story manifest has no size and CRC32 for %s, so that file can no longer be removed.', 'the-shorthand-editor' ),
						esc_html( (string) $name )
					),
					'1.0.10'
				);

				continue;
			}

			$manifest[ $path ] = array(
				'size' => (int) $entry['size'],
				'crc'  => (int) $entry['crc'],
			);
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
}
