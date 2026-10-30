<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use ZipArchive;

/**
 * A story archive, opened once and read before it is unpacked.
 *
 * The index and the two documents are taken while the handle is open, so a
 * caller never has to hold one. `unpack_to()` closes it, whether or not the
 * extract works.
 */
class Archive {

	/**
	 * Open handle to the archive.
	 *
	 * @var \ZipArchive
	 */
	private $zip;

	/**
	 * Where the archive was assembled, for the error messages.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Bundle path to size and CRC32, read from the index.
	 *
	 * @var array<string, array{size: int, crc: int}>
	 */
	private $manifest;

	/**
	 * Entry names that can be one file on some host, in groups.
	 *
	 * @var array<int, string[]>
	 */
	private $collisions;

	/**
	 * The story documents, by bundle path.
	 *
	 * @var array<string, string>
	 */
	private $documents;

	/**
	 * Built by `open()`, which has read the index already.
	 *
	 * @param \ZipArchive $zip      Open archive.
	 * @param string      $path     Path the archive was opened from.
	 * @param array       $manifest Index of the archive.
	 */
	private function __construct( ZipArchive $zip, string $path, array $manifest ) {
		$this->zip        = $zip;
		$this->path       = $path;
		$this->manifest   = $manifest;
		$names            = self::names( $zip );
		$this->collisions = Manifest::collisions( $names );
		$this->documents  = self::documents( $zip, $names );
	}

	/**
	 * Opens an archive and reads everything that needs the handle.
	 *
	 * @param string $path Assembled archive on the local file system.
	 * @return \Shorthand\Services\Files\Archive|\WP_Error
	 */
	public static function open( string $path ) {
		$zip    = new ZipArchive();
		$opened = $zip->open( $path );

		if ( true !== $opened ) {
			$error = self::get_error( 'Could not open story archive', $path );
			$error->add( 'zip', self::get_zip_error_message( $opened ), $opened );

			return $error;
		}

		$manifest = Manifest::from_archive( $zip );

		if ( is_wp_error( $manifest ) ) {
			$zip->close();

			return $manifest;
		}

		return new Archive( $zip, $path, $manifest );
	}

	/**
	 * The archive index: every entry, with its size and CRC32.
	 *
	 * @return array<string, array{size: int, crc: int}>
	 */
	public function manifest(): array {
		return $this->manifest;
	}

	/**
	 * Entry names that can be one file on some host, from `Manifest::collisions()`.
	 *
	 * @return array<int, string[]>
	 */
	public function collisions(): array {
		return $this->collisions;
	}

	/**
	 * One story document, or an empty string where the archive has none.
	 *
	 * @param string $name Archive entry name, from `Manifest::DOCUMENTS`.
	 */
	public function document( string $name ): string {
		return isset( $this->documents[ $name ] ) ? $this->documents[ $name ] : '';
	}

	/**
	 * Extracts every entry into a local directory, and closes the archive.
	 *
	 * `ZipArchive::extractTo()` writes through native syscalls, so the target
	 * has to be a real directory rather than a stream wrapper.
	 *
	 * The archive is closed whether or not the extract worked, so the staging
	 * directory it sits in can be removed.
	 *
	 * @param string $dir Local directory to extract into.
	 * @return true|\WP_Error
	 */
	public function unpack_to( string $dir ) {
		wp_mkdir_p( $dir );

		$extracted = $this->zip->extractTo( $dir );

		/* Read first: PHP 7 cannot report the status of a closed archive. */
		$status  = $this->zip->status;
		$message = $this->zip->getStatusString();

		$closed = $this->zip->close();

		if ( ! $extracted ) {
			$error = self::get_error( 'Could not extract story archive', $this->path );
			$error->add( 'zip', $message, $status );

			return $error;
		}

		if ( ! $closed ) {
			return self::get_error( 'Could not close story archive', $this->path );
		}

		return true;
	}

	/**
	 * Every entry name, in archive order.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return array<int, string> Entry name by index.
	 */
	private static function names( ZipArchive $zip ): array {
		$names = array();

		$count = $zip->count();

		for ( $idx = 0; $idx < $count; $idx++ ) {
			$name = $zip->getNameIndex( $idx );

			if ( false !== $name ) {
				$names[ $idx ] = $name;
			}
		}

		return $names;
	}

	/**
	 * The story documents, each read from the entry that lands at its path.
	 *
	 * `./article.html` lands at `article.html`, and where two entries land at
	 * one path the later is the file left, as in `Manifest::from_archive()`.
	 *
	 * @param \ZipArchive        $zip   Open archive.
	 * @param array<int, string> $names Entry name by index.
	 * @return array<string, string> Contents by document name; empty where absent.
	 */
	private static function documents( ZipArchive $zip, array $names ): array {
		$found = array();

		foreach ( $names as $idx => $name ) {
			$path = Manifest::path( $name );

			if ( in_array( $path, Manifest::DOCUMENTS, true ) ) {
				$found[ $path ] = $idx;
			}
		}

		$documents = array();

		foreach ( Manifest::DOCUMENTS as $name ) {
			$contents           = isset( $found[ $name ] ) ? $zip->getFromIndex( $found[ $name ] ) : false;
			$documents[ $name ] = false === $contents ? '' : $contents;
		}

		return $documents;
	}

	/**
	 * An archive failure, carrying the file size that usually explains it.
	 *
	 * @param string $summary What failed.
	 * @param string $path    Archive it failed on.
	 */
	private static function get_error( string $summary, string $path ): WP_Error {
		$error = new WP_Error( 'file', "{$summary} at {$path}.", $path );

		$file_size = wp_filesize( $path );
		$error->add( 'file_size', "File size is {$file_size}.", $file_size );

		return $error;
	}

	/**
	 * How a failure to open reads in an error message.
	 *
	 * @param int|bool $err Status `ZipArchive::open()` returned.
	 */
	private static function get_zip_error_message( $err ): string {
		if ( false === $err ) {
			return 'Unknown error.';
		}

		switch ( $err ) {
			case ZipArchive::ER_EXISTS:
				return 'File already exists.';
			case ZipArchive::ER_INCONS:
				return 'Zip archive inconsistent.';
			case ZipArchive::ER_INVAL:
				return 'Invalid argument.';
			case ZipArchive::ER_MEMORY:
				return 'Malloc failure.';
			case ZipArchive::ER_NOENT:
				return 'No such file.';
			case ZipArchive::ER_NOZIP:
				return 'Not a zip archive.';
			case ZipArchive::ER_OPEN:
				return 'Can\'t open file.';
			case ZipArchive::ER_READ:
				return 'Read error.';
			case ZipArchive::ER_SEEK:
				return 'Seek error.';
		}

		return "Error code {$err}.";
	}
}
