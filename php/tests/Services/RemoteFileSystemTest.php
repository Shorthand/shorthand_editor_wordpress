<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\FileSystemService;
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\Support\FileSystemContractTestCase;

final class RemoteFileSystemTest extends FileSystemContractTestCase {

	/** @var \Shorthand\Tests\Support\FakeRemoteFileSystem */
	private $subject;

	protected function setUp(): void {
		parent::setUp();

		$this->subject = new FakeRemoteFileSystem();
	}

	/**
	 * An object store has no directories, so `delete_dir()` has nothing to do.
	 */
	public function test_delete_dir_reports_success_without_acting(): void {
		$this->assertTrue( $this->subject->delete_dir( $this->bundle_dir ) );
	}

	/**
	 * A remote host cannot enumerate, so a tree cannot be deleted blind.
	 * Callers fall back to `delete_manifest()`.
	 */
	public function test_delete_tree_is_refused(): void {
		$this->stage( 'assets/media/photo.jpg', 'binary' );
		$this->copy_tree( null );

		$this->assertFalse( $this->subject->delete_tree( $this->bundle_dir ) );
		$this->assertNotSame( array(), $this->subject->objects() );
	}

	/**
	 * `mkdir()` succeeds without creating anything, and `copy_tree()` still
	 * lands every file.
	 */
	public function test_copy_tree_writes_files_although_no_directory_is_created(): void {
		$this->stage( 'assets/media/photo.jpg', 'binary' );

		$this->copy_tree( null );

		$this->assertGreaterThan( 0, $this->subject->make_dir_calls() );
		$this->assertDirectoryDoesNotExist( $this->bundle_dir );
		$this->assertSame(
			array( $this->bundle_dir . '/assets/media/photo.jpg' => 'binary' ),
			$this->subject->objects()
		);
	}

	protected function file_system(): FileSystemService {
		return $this->subject;
	}

	/**
	 * @return array<string, string>
	 */
	protected function bundle_contents(): array {
		$contents = array();
		$offset   = strlen( $this->bundle_dir ) + 1;

		foreach ( $this->subject->objects() as $key => $value ) {
			$contents[ substr( $key, $offset ) ] = $value;
		}

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

	/**
	 * The uploads directory cannot be listed, so nothing may try.
	 *
	 * `BaseFileSystem` holds the shared behaviour, so both files are read.
	 * The recursive delete check is confined to `RemoteFileSystem`, because
	 * `BaseFileSystem::delete_temp_dir()` targets the local temp directory,
	 * which can be enumerated on every host.
	 */
	public function test_nothing_in_the_remote_path_enumerates_a_directory(): void {
		foreach ( array( 'RemoteFileSystem', 'BaseFileSystem' ) as $class ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b(scandir|glob|opendir|readdir|list_files|rmdir)\s*\(/',
				$this->source_of( $class ),
				$class . ' enumerates or removes a directory.'
			);
		}

		$this->assertDoesNotMatchRegularExpression(
			'/->delete\s*\([^)]*,\s*true/',
			$this->source_of( 'RemoteFileSystem' ),
			'RemoteFileSystem deletes recursively.'
		);
	}

	/**
	 * A service class with its comments stripped.
	 *
	 * @param string $class Class name under `Shorthand\Services`.
	 * @return string
	 */
	private function source_of( string $class ): string {
		$source = (string) file_get_contents( __DIR__ . '/../../src/lib/Services/' . $class . '.php' );

		return (string) preg_replace( '#/\*.*?\*/#s', '', $source );
	}
}
