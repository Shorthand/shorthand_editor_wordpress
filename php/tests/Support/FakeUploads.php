<?php

declare(strict_types=1);

namespace Shorthand\Tests\Support;

use Shorthand\Services\Files\Uploads;

/**
 * An uploads directory that is an in-memory object store.
 *
 * Reproduces the constraint table in `docs/services/file-system.md`: uploads
 * cannot be enumerated, `make_dir()` reports success without creating
 * anything, and every write and delete is a round trip that is counted here.
 *
 * The staging directory is untouched by this class, because it is local on
 * every host.
 */
final class FakeUploads implements Uploads {

	/**
	 * Object key to file contents.
	 *
	 * @var array<string, string>
	 */
	private $objects = array();

	/** @var int */
	private $writes = 0;

	/** @var int */
	private $deletes = 0;

	/** @var int */
	private $make_dir_calls = 0;

	/** @var \WP_Error|null */
	private $write_error = null;

	/**
	 * @return bool|\WP_Error
	 */
	public function write( string $source_path, string $dest_path ) {
		++$this->writes;

		if ( null !== $this->write_error ) {
			return $this->write_error;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local staging directory, not uploads.
		$contents = file_get_contents( $source_path );

		if ( false === $contents ) {
			return false;
		}

		$this->objects[ $dest_path ] = $contents;

		return true;
	}

	public function read_into( string $path, string $local_path ): bool {
		if ( ! isset( $this->objects[ $path ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the local staging directory, not uploads.
		return false !== file_put_contents( $local_path, $this->objects[ $path ], FILE_APPEND );
	}

	public function delete( string $path ): bool {
		++$this->deletes;

		if ( ! isset( $this->objects[ $path ] ) ) {
			return false;
		}

		unset( $this->objects[ $path ] );

		return true;
	}

	/**
	 * An object store has no directories, so this creates nothing.
	 */
	public function make_dir( string $path ): bool {
		++$this->make_dir_calls;

		return true;
	}

	/**
	 * Makes every later write fail the way a host that refuses one does.
	 *
	 * @param \WP_Error $error Error to answer with.
	 */
	public function fail_writes( \WP_Error $error ): void {
		$this->write_error = $error;
	}

	/**
	 * Seeds the store, standing in for a write made by an earlier request.
	 *
	 * @param string $path     Object key.
	 * @param string $contents Contents to store under it.
	 */
	public function put( string $path, string $contents ): void {
		$this->objects[ $path ] = $contents;
	}

	/**
	 * Object keys, and the contents stored under them.
	 *
	 * @return array<string, string>
	 */
	public function objects(): array {
		ksort( $this->objects );

		return $this->objects;
	}

	public function writes(): int {
		return $this->writes;
	}

	public function deletes(): int {
		return $this->deletes;
	}

	public function make_dir_calls(): int {
		return $this->make_dir_calls;
	}

	public function reset_counts(): void {
		$this->writes         = 0;
		$this->deletes        = 0;
		$this->make_dir_calls = 0;
	}
}
