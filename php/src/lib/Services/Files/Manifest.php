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
	 * The two files rewritten on every publish.
	 */
	const DOCUMENTS = array( 'article.html', 'head.html' );

	/**
	 * Reads an archive index, without extracting anything.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return array<string, array{size: int, crc: int}>|\WP_Error Entry name to size and CRC32.
	 */
	public static function from_archive( ZipArchive $zip ) {
		$manifest = array();

		for ( $idx = 0; $idx < $zip->numFiles; $idx++ ) {
			$stat = $zip->statIndex( $idx );

			if ( false === $stat || self::is_directory_entry( $stat['name'] ) ) {
				continue;
			}

			if ( ! self::is_safe_name( $stat['name'] ) ) {
				return new WP_Error( 'file', "The story archive names a file outside the bundle: {$stat['name']}.", $stat['name'] );
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
	 * Whether an entry name is usable as a path inside the bundle.
	 *
	 * Entry names become path segments the same way a story ID and a request
	 * nonce do, and unlike those two they are received rather than generated.
	 * A name that escapes fails the whole publish: skipping it would leave the
	 * bundle incomplete, and the manifest naming a file that is not on disk.
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
	 * @param mixed $value Stored meta value.
	 * @return array<string, array{size: int, crc: int}>
	 */
	public static function from_meta( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$manifest = array();

		foreach ( $value as $name => $entry ) {
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

			$manifest[ (string) $name ] = array(
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

	/**
	 * Moves the story documents under a per-publish directory.
	 *
	 * The bundle path is fixed for the life of a post, so `article.html` and
	 * `head.html` would otherwise be rewritten in place on every publish, and
	 * a host that caps modifications per path would eventually refuse them.
	 *
	 * The entry keeps a `from` key naming where the file was unpacked, which
	 * is what `Shorthand\Services\Files\Bundle` copies from. The previous
	 * directory is removed by the next publish's manifest diff.
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
