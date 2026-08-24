<?php

declare(strict_types=1);

namespace Shorthand\Tests\Support;

use Shorthand\Services\RemoteFileSystem;

/**
 * A `RemoteFileSystem` whose uploads directory is an in-memory object store.
 *
 * Reproduces the remote uploads constraint table in
 * `docs/services/file-system.md`: uploads cannot be enumerated, `mkdir()`
 * reports success without creating anything, and every write and delete is a
 * round trip that is counted here.
 *
 * The staging directory is untouched by this class, because it is local on
 * every host and the inherited behaviour is already correct for it.
 */
final class FakeRemoteFileSystem extends RemoteFileSystem {

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

	/**
	 * An object store has no directories, so this creates nothing.
	 */
	public function make_dir( string $path ): bool {
		++$this->make_dir_calls;

		return true;
	}

	public function delete_file( string $path ): bool {
		++$this->deletes;

		if ( ! isset( $this->objects[ $path ] ) ) {
			return false;
		}

		unset( $this->objects[ $path ] );

		return true;
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

	/**
	 * @return bool|\WP_Error
	 */
	protected function write_file( string $source_path, string $dest_path ) {
		++$this->writes;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local staging directory, not uploads.
		$contents = file_get_contents( $source_path );

		if ( false === $contents ) {
			return false;
		}

		$this->objects[ $dest_path ] = $contents;

		return true;
	}
}
