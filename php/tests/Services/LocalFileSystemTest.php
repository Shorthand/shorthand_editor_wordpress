<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\FileSystemService;
use Shorthand\Tests\Support\CountingLocalFileSystem;
use Shorthand\Tests\Support\FileSystemContractTestCase;

final class LocalFileSystemTest extends FileSystemContractTestCase {

	/** @var \Shorthand\Tests\Support\CountingLocalFileSystem */
	private $subject;

	protected function setUp(): void {
		parent::setUp();

		$this->subject = new CountingLocalFileSystem();
	}

	public function test_delete_dir_removes_an_empty_directory(): void {
		mkdir( $this->bundle_dir, 0777, true );

		$this->assertTrue( $this->subject->delete_dir( $this->bundle_dir ) );
		$this->assertDirectoryDoesNotExist( $this->bundle_dir );
	}

	public function test_delete_dir_reports_success_for_a_directory_that_is_already_gone(): void {
		$this->assertTrue( $this->subject->delete_dir( $this->bundle_dir ) );
	}

	/**
	 * A local host can enumerate, so a bundle can be removed without a manifest.
	 */
	public function test_delete_tree_removes_the_bundle_and_its_contents(): void {
		$this->stage( 'assets/media/photo.jpg', 'binary' );
		$this->subject->copy_tree( $this->staging_dir, $this->bundle_dir, null );

		$this->assertTrue( $this->subject->delete_tree( $this->bundle_dir ) );
		$this->assertDirectoryDoesNotExist( $this->bundle_dir );
	}

	public function test_delete_tree_reports_success_for_a_bundle_that_is_already_gone(): void {
		$this->assertTrue( $this->subject->delete_tree( $this->bundle_dir ) );
	}

	protected function file_system(): FileSystemService {
		return $this->subject;
	}

	/**
	 * @return array<string, string>
	 */
	protected function bundle_contents(): array {
		if ( ! is_dir( $this->bundle_dir ) ) {
			return array();
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->bundle_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$contents = array();
		$offset   = strlen( $this->bundle_dir ) + 1;

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$contents[ substr( $file->getPathname(), $offset ) ] = file_get_contents( $file->getPathname() );
			}
		}

		ksort( $contents );

		return $contents;
	}

	protected function writes(): int {
		return $this->subject->writes();
	}

	protected function deletes(): int {
		return $this->subject->deletes();
	}

	protected function reset_counts(): void {
		$this->subject->reset_counts();
	}
}
