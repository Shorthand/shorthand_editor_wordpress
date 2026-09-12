<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A scratch directory on the web server's own file system.
 *
 * The local file system is the one thing every host has, so the pipeline does
 * its work here and hands finished files to `Uploads`. Two things need it:
 * `ZipArchive::extractTo()` uses native syscalls and ignores stream wrappers,
 * and appending has no equivalent in `WP_Filesystem`.
 *
 * A staging directory belongs to one publish and is discarded with it.
 */
class Staging {

	/**
	 * @var \Shorthand\Services\Files\Uploads
	 */
	private $uploads;

	/**
	 * @var string
	 */
	private $path;

	/**
	 * @param \Shorthand\Services\Files\Uploads $uploads Uploads directory to read chunks from.
	 * @param string                            $path    Directory this instance owns.
	 */
	private function __construct( Uploads $uploads, string $path ) {
		$this->uploads = $uploads;
		$this->path    = $path;
	}

	/**
	 * Creates an empty staging directory under the local temp directory.
	 *
	 * @param \Shorthand\Services\Files\Uploads $uploads Uploads directory to read chunks from.
	 * @param string                            $prefix  Prefix for the directory name.
	 */
	public static function open( Uploads $uploads, string $prefix ): Staging {
		/* Unpacking an archive wants the admin memory limit. */
		FileSystem::boot();

		$base = untrailingslashit( get_temp_dir() );

		do {
			$path = $base . '/' . $prefix . wp_rand( 100000, 999999 );
		} while ( file_exists( $path ) );

		wp_mkdir_p( $path );

		return new Staging( $uploads, $path );
	}

	/**
	 * Absolute path of a file or directory inside the staging directory.
	 *
	 * @param string $name Name relative to the staging directory.
	 */
	public function file( string $name ): string {
		return $this->path . '/' . $name;
	}

	/**
	 * Concatenates downloaded chunks into one local file, in order.
	 *
	 * The chunks are in uploads, because they arrive one WP Cron request at a
	 * time and nothing else survives between requests. This is the only place
	 * anything is read back out of uploads.
	 *
	 * @param string[] $chunk_paths Chunk paths in uploads, in download order.
	 * @param string   $name        Name to assemble them under, relative to the staging directory.
	 * @return string|null Absolute path of the assembled file, or null when a chunk could not be read.
	 */
	public function gather( array $chunk_paths, string $name ): ?string {
		$dest = $this->file( $name );

		foreach ( $chunk_paths as $chunk_path ) {
			if ( ! $this->uploads->read_into( $chunk_path, $dest ) ) {
				return null;
			}
		}

		return $dest;
	}

	/**
	 * Removes the staging directory and everything under it.
	 *
	 * @return bool True when the directory is gone.
	 */
	public function discard(): bool {
		$fs = FileSystem::boot();

		if ( ! $fs->is_dir( $this->path ) ) {
			return true;
		}

		return $fs->delete( $this->path, true );
	}
}
