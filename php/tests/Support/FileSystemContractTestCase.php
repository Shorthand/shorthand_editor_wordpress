<?php

declare(strict_types=1);

namespace Shorthand\Tests\Support;

use Shorthand\Services\FileSystemService;
use Shorthand\Tests\WordPressTestCase;

/**
 * The contract every `FileSystemService` implementation must satisfy.
 *
 * One subclass per implementation. The staging directory is a real local
 * directory in every case, because it is local on every host. The bundle
 * directory is whatever the implementation under test writes to, read back
 * through `bundle_contents()`.
 *
 * `stage()` both writes a file and records it in the source manifest, which
 * stands in for the archive index the caller builds with
 * `Shorthand\Services\BundleManifest::from_archive()`.
 */
abstract class FileSystemContractTestCase extends WordPressTestCase {

	/** @var string */
	protected $temp_root;

	/** @var string */
	protected $staging_dir;

	/** @var string */
	protected $bundle_dir;

	/** @var array<string, array{size: int, crc: int}> */
	protected $source_manifest = array();

	protected function setUp(): void {
		parent::setUp();

		$this->temp_root = sys_get_temp_dir() . '/sh_fs_' . getmypid() . '_' . $this->next_id();
		mkdir( $this->temp_root, 0777, true );
		tests_wp_set_temp_dir( $this->temp_root );

		$this->staging_dir     = $this->temp_root . '/staging';
		$this->bundle_dir      = $this->temp_root . '/bundle';
		$this->source_manifest = array();
		mkdir( $this->staging_dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->temp_root );

		parent::tearDown();
	}

	/**
	 * The implementation under test.
	 */
	abstract protected function file_system(): FileSystemService;

	/**
	 * The bundle directory as the implementation left it.
	 *
	 * @return array<string, string> Path relative to the bundle directory, to contents.
	 */
	abstract protected function bundle_contents(): array;

	abstract protected function writes(): int;

	abstract protected function deletes(): int;

	abstract protected function reset_counts(): void;

	public function test_copy_tree_writes_every_staged_file_when_there_is_no_manifest(): void {
		$this->stage( 'article.html', 'first' );
		$this->stage( 'assets/media/photo.jpg', 'binary' );

		$manifest = $this->copy_tree( null );

		$this->assertIsArray( $manifest );
		$this->assertSame( array( 'article.html', 'assets/media/photo.jpg' ), array_keys( $manifest ) );
		$this->assertSame(
			array(
				'article.html'           => 'first',
				'assets/media/photo.jpg' => 'binary',
			),
			$this->bundle_contents()
		);
		$this->assertSame( 2, $this->writes() );
	}

	/**
	 * A republish with no edits is the case that has to be cheap.
	 */
	public function test_copy_tree_writes_nothing_when_every_file_is_unchanged(): void {
		$this->stage( 'article.html', 'first' );
		$this->stage( 'assets/media/photo.jpg', 'binary' );

		$manifest = $this->copy_tree( null );
		$this->reset_counts();

		$republished = $this->copy_tree( $manifest );

		$this->assertSame( $manifest, $republished );
		$this->assertSame( 0, $this->writes() );
		$this->assertSame( 0, $this->deletes() );
	}

	public function test_copy_tree_writes_only_the_files_whose_contents_changed(): void {
		$this->stage( 'article.html', 'first' );
		$this->stage( 'assets/media/photo.jpg', 'binary' );

		$manifest = $this->copy_tree( null );
		$this->reset_counts();

		$this->stage( 'article.html', 'second' );

		$republished = $this->copy_tree( $manifest );

		$this->assertSame( 1, $this->writes() );
		$this->assertSame( 'second', $this->bundle_contents()['article.html'] );
		$this->assertNotSame( $manifest['article.html'], $republished['article.html'] );
		$this->assertSame( $manifest['assets/media/photo.jpg'], $republished['assets/media/photo.jpg'] );
	}

	/**
	 * Same size, different contents. Size alone would miss this.
	 */
	public function test_copy_tree_writes_a_file_that_changed_without_changing_size(): void {
		$this->stage( 'article.html', 'aaaaa' );

		$manifest = $this->copy_tree( null );
		$this->reset_counts();

		$this->stage( 'article.html', 'bbbbb' );

		$this->copy_tree( $manifest );

		$this->assertSame( 1, $this->writes() );
		$this->assertSame( 'bbbbb', $this->bundle_contents()['article.html'] );
	}

	public function test_copy_tree_writes_a_file_the_stored_manifest_does_not_name(): void {
		$this->stage( 'article.html', 'first' );

		$manifest = $this->copy_tree( null );
		$this->reset_counts();

		$this->stage( 'assets/media/new.jpg', 'binary' );

		$republished = $this->copy_tree( $manifest );

		$this->assertSame( 1, $this->writes() );
		$this->assertArrayHasKey( 'assets/media/new.jpg', $republished );
	}

	public function test_copy_tree_omits_a_file_that_left_the_archive_from_the_new_manifest(): void {
		$this->stage( 'article.html', 'first' );
		$this->stage( 'assets/old.jpg', 'gone' );

		$manifest = $this->copy_tree( null );

		$this->unstage( 'assets/old.jpg' );

		$republished = $this->copy_tree( $manifest );

		$this->assertSame( array( 'article.html' ), array_keys( $republished ) );
		$this->assertArrayHasKey( 'assets/old.jpg', array_diff_key( $manifest, $republished ) );
	}

	public function test_delete_manifest_removes_every_file_the_manifest_names(): void {
		$this->stage( 'article.html', 'first' );
		$this->stage( 'assets/media/photo.jpg', 'binary' );

		$manifest = $this->copy_tree( null );
		$this->reset_counts();

		$this->assertTrue( $this->file_system()->delete_manifest( $this->bundle_dir, $manifest ) );
		$this->assertSame( array(), $this->bundle_contents() );
		$this->assertSame( 2, $this->deletes() );
	}

	public function test_make_temp_dir_creates_a_new_local_directory_under_the_temp_dir(): void {
		$first  = $this->file_system()->make_temp_dir( 'sh_pull_' );
		$second = $this->file_system()->make_temp_dir( 'sh_pull_' );

		$this->assertNotSame( $first, $second );
		$this->assertDirectoryExists( $first );
		$this->assertStringStartsWith( $this->temp_root . '/sh_pull_', $first );
	}

	public function test_delete_temp_dir_removes_the_staging_tree(): void {
		$staging = $this->file_system()->make_temp_dir( 'sh_pull_' );
		mkdir( $staging . '/unpacked', 0777, true );
		file_put_contents( $staging . '/unpacked/article.html', 'first' );

		$this->assertTrue( $this->file_system()->delete_temp_dir( $staging ) );
		$this->assertDirectoryDoesNotExist( $staging );
	}

	public function test_delete_temp_dir_reports_success_for_a_directory_that_is_already_gone(): void {
		$this->assertTrue( $this->file_system()->delete_temp_dir( $this->temp_root . '/never_created' ) );
	}

	public function test_join_pieces_concatenates_the_parts_in_order(): void {
		$this->stage( 'file-0.part', 'head' );
		$this->stage( 'file-1.part', 'tail' );

		$dest = $this->temp_root . '/archive.zip';

		$this->assertTrue(
			$this->file_system()->join_pieces(
				array( $this->staging_dir . '/file-0.part', $this->staging_dir . '/file-1.part' ),
				$dest
			)
		);
		$this->assertSame( 'headtail', file_get_contents( $dest ) );
	}

	/**
	 * Copies the staged files, as the publish pipeline would.
	 *
	 * @param array|null $dest_manifest Manifest of the last publish, if any.
	 * @return array|\WP_Error
	 */
	protected function copy_tree( ?array $dest_manifest ) {
		return $this->file_system()->copy_tree( $this->staging_dir, $this->bundle_dir, $this->source_manifest, $dest_manifest );
	}

	protected function stage( string $relative_path, string $contents ): void {
		$path = $this->staging_dir . '/' . $relative_path;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, $contents );

		$this->source_manifest[ $relative_path ] = array(
			'size' => strlen( $contents ),
			'crc'  => crc32( $contents ),
		);

		ksort( $this->source_manifest );
	}

	protected function unstage( string $relative_path ): void {
		unlink( $this->staging_dir . '/' . $relative_path );

		unset( $this->source_manifest[ $relative_path ] );
	}

	protected function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;

			if ( is_dir( $child ) ) {
				$this->remove_tree( $child );
			} else {
				unlink( $child );
			}
		}

		rmdir( $path );
	}

	private function next_id(): int {
		static $id = 0;

		return ++$id;
	}
}
