<?php

declare(strict_types=1);

namespace Shorthand\Tests\Support;

use Shorthand\Services\LocalFileSystem;

/**
 * A `LocalFileSystem` that counts the round trips a remote host would charge for.
 *
 * Behaviour is unchanged; the counters exist so the same contract can assert
 * the same write and delete counts against both implementations.
 */
final class CountingLocalFileSystem extends LocalFileSystem {

	/** @var int */
	private $writes = 0;

	/** @var int */
	private $deletes = 0;

	public function delete_file( string $path ): bool {
		++$this->deletes;

		return parent::delete_file( $path );
	}

	public function writes(): int {
		return $this->writes;
	}

	public function deletes(): int {
		return $this->deletes;
	}

	public function reset_counts(): void {
		$this->writes  = 0;
		$this->deletes = 0;
	}

	/**
	 * @return bool|\WP_Error
	 */
	protected function write_file( string $source_path, string $dest_path ) {
		++$this->writes;

		return parent::write_file( $source_path, $dest_path );
	}
}
