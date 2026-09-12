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
 * caller never has to hold one. `unpack_to()` closes it.
 */
class Archive {

	/**
	 * @var \ZipArchive
	 */
	private $zip;

	/**
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
	 * The story documents, by archive entry name.
	 *
	 * @var array<string, string>
	 */
	private $documents;

	/**
	 * @param \ZipArchive $zip      Open archive.
	 * @param string      $path     Path the archive was opened from.
	 * @param array       $manifest Index of the archive.
	 */
	private function __construct( ZipArchive $zip, string $path, array $manifest ) {
		$this->zip       = $zip;
		$this->path      = $path;
		$this->manifest  = $manifest;
		$this->documents = array();

		foreach ( Manifest::DOCUMENTS as $name ) {
			$contents                 = $zip->getFromName( $name );
			$this->documents[ $name ] = false === $contents ? '' : $contents;
		}
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

		return new Archive( $zip, $path, Manifest::from_archive( $zip ) );
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
	 * @param string $dir Local directory to extract into.
	 * @return true|\WP_Error
	 */
	public function unpack_to( string $dir ) {
		wp_mkdir_p( $dir );

		if ( $this->zip->extractTo( $dir ) && $this->zip->close() ) {
			return true;
		}

		$error = self::get_error( 'Could not extract story archive', $this->path );
		$error->add( 'zip', $this->zip->getStatusString(), $this->zip->status );

		return $error;
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
