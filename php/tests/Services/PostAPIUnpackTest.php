<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Files\BundleStore;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Services\StoryCover;
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Tests\Support\FakeUploads;
use Shorthand\Tests\WordPressTestCase;
use ZipArchive;

/**
 * Publishing a story where the uploads directory is an object store.
 *
 * A publish reads its chunks back out of uploads, assembles and unpacks them
 * locally, and copies the difference in. `ZipArchive::extractTo()` uses native
 * syscalls, so the only way this works is if it never targets uploads.
 */
final class PostAPIUnpackTest extends WordPressTestCase {

	/**
	 * Bundle directory every assertion is written against.
	 */
	const BUNDLE = 'vip://wp-content/uploads/shorthand/7/aBc123';

	/** @var string */
	private $temp_root;

	/** @var \Shorthand\Tests\Support\FakeUploads */
	private $uploads;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );

		$this->temp_root = sys_get_temp_dir() . '/sh_unpack_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
		tests_wp_set_temp_dir( $this->temp_root );

		$this->uploads = new FakeUploads();
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->temp_root );

		parent::tearDown();
	}

	public function test_the_archive_is_unpacked_locally_and_copied_into_the_bundle(): void {
		$result = $this->publish(
			'pull1',
			array(
				'head.html'              => '<link rel="stylesheet" href="assets/theme.css">',
				'article.html'           => '<h1>Story</h1>',
				'assets/theme.css'       => 'body{}',
				'assets/media/photo.jpg' => 'binary',
			)
		);

		$this->assertNull( $result );

		$this->assertSame(
			array(
				self::BUNDLE . '/article.html'           => '<h1>Story</h1>',
				self::BUNDLE . '/assets/media/photo.jpg' => 'binary',
				self::BUNDLE . '/assets/theme.css'       => 'body{}',
				self::BUNDLE . '/head.html'              => '<link rel="stylesheet" href="assets/theme.css">',
			),
			$this->bundle_objects()
		);
		$this->assertSame( 4, $this->uploads->writes() );
	}

	/**
	 * The chunks are the one thing read back out of uploads, and a publish
	 * cannot start until they are one archive again.
	 */
	public function test_the_chunks_of_a_download_are_assembled_in_order(): void {
		$archive = $this->make_archive( array( 'article.html' => 'article' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$bytes = file_get_contents( $archive );
		$half  = (int) ( strlen( $bytes ) / 2 );

		$this->uploads->put( self::BUNDLE . '_pull1_0.part', substr( $bytes, 0, $half ) );
		$this->uploads->put( self::BUNDLE . '_pull1_1.part', substr( $bytes, $half ) );

		$this->assertNull( $this->make_post_api()->publish_story_bundle( 7, 'aBc123', 'pull1', 2 ) );

		$this->assertSame(
			array( self::BUNDLE . '/article.html' ),
			array_keys( $this->bundle_objects() )
		);
	}

	public function test_a_missing_chunk_fails_the_publish(): void {
		$result = $this->make_post_api()->publish_story_bundle( 7, 'aBc123', 'pull1', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'file', $result->get_error_codes() );
		$this->assertSame( array(), $this->bundle_objects() );
	}

	public function test_the_two_documents_are_stored_as_post_meta(): void {
		$this->publish(
			'pull1',
			array(
				'head.html'    => 'head markup',
				'article.html' => 'article markup',
			)
		);

		$this->assertSame( 'head markup', get_post_meta( 7, 'story_head', true ) );
		$this->assertSame( 'article markup', get_post_meta( 7, 'story_body', true ) );
	}

	/**
	 * The whole point of the manifest: a republish costs the size of the edit.
	 */
	public function test_a_republish_with_no_change_writes_nothing(): void {
		$entries = array(
			'head.html'              => 'head',
			'article.html'           => 'article',
			'assets/media/photo.jpg' => 'binary',
		);

		$this->publish( 'pull1', $entries );
		$this->uploads->reset_counts();

		$this->publish( 'pull2', $entries );

		$this->assertSame( 0, $this->uploads->writes() );
		$this->assertSame( 0, $this->uploads->deletes() );
	}

	/**
	 * The documents sit at the bundle root, at the paths the archive names.
	 */
	public function test_the_post_processing_filters_receive_the_document_paths(): void {
		$this->publish(
			'pull1',
			array(
				'head.html'    => 'head',
				'article.html' => 'article',
			)
		);

		$this->assertSame(
			array( array( self::BUNDLE, self::BUNDLE . '/article.html' ) ),
			tests_wp_get_filter_args( 'theshed_post_process_body' )
		);
		$this->assertSame(
			array( array( self::BUNDLE, self::BUNDLE . '/head.html' ) ),
			tests_wp_get_filter_args( 'theshed_post_process_head' )
		);
	}

	/**
	 * Releases 1.0.8 and 1.0.9 moved the documents to `docs/{nonce}/`. The
	 * stored manifest names that pair, so the next publish removes it.
	 */
	public function test_documents_moved_by_an_earlier_release_return_to_the_bundle_root(): void {
		$this->uploads->put( self::BUNDLE . '/docs/12345/article.html', 'article' );
		$this->uploads->put( self::BUNDLE . '/docs/12345/head.html', 'head' );

		tests_wp_set_post_meta(
			7,
			'story_manifest',
			array(
				'docs/12345/article.html' => array(
					'size' => 7,
					'crc'  => crc32( 'article' ),
				),
				'docs/12345/head.html'    => array(
					'size' => 4,
					'crc'  => crc32( 'head' ),
				),
			)
		);

		$this->publish(
			'pull1',
			array(
				'head.html'    => 'head',
				'article.html' => 'article',
			)
		);

		$this->assertSame(
			array(
				self::BUNDLE . '/article.html' => 'article',
				self::BUNDLE . '/head.html'    => 'head',
			),
			$this->bundle_objects()
		);
		$this->assertSame( array( 'article.html', 'head.html' ), array_keys( get_post_meta( 7, 'story_manifest', true ) ) );
	}

	public function test_a_republish_writes_only_the_files_that_changed(): void {
		$this->publish(
			'pull1',
			array(
				'head.html'              => 'head',
				'article.html'           => 'article',
				'assets/media/photo.jpg' => 'binary',
			)
		);
		$this->uploads->reset_counts();

		$this->publish(
			'pull2',
			array(
				'head.html'              => 'head',
				'article.html'           => 'article, edited',
				'assets/media/photo.jpg' => 'binary, edited',
			)
		);

		$objects = $this->bundle_objects();

		$this->assertSame( 2, $this->uploads->writes() );
		$this->assertSame( 'article, edited', $objects[ self::BUNDLE . '/article.html' ] );
		$this->assertSame( 'binary, edited', $objects[ self::BUNDLE . '/assets/media/photo.jpg' ] );
	}

	public function test_a_file_that_left_the_story_is_deleted_from_the_bundle(): void {
		$this->publish(
			'pull1',
			array(
				'article.html'         => 'article',
				'assets/media/old.jpg' => 'binary',
			)
		);
		$this->uploads->reset_counts();

		$this->publish( 'pull2', array( 'article.html' => 'article' ) );

		$this->assertSame( 1, $this->uploads->deletes() );
		$this->assertSame(
			array( self::BUNDLE . '/article.html' ),
			array_keys( $this->bundle_objects() )
		);
		$this->assertSame( array( 'article.html' ), array_keys( get_post_meta( 7, 'story_manifest', true ) ) );
	}

	/**
	 * The host folds case, so the old name and the new one are one file there.
	 * Pruning the old name would delete what the copy has just written.
	 */
	public function test_a_file_renamed_only_in_case_is_not_pruned(): void {
		$this->publish(
			'pull1',
			array(
				'article.html'           => 'article',
				'assets/media/Photo.JPG' => 'binary',
			)
		);
		$this->uploads->reset_counts();

		$this->publish(
			'pull2',
			array(
				'article.html'           => 'article',
				'assets/media/photo.jpg' => 'binary',
			)
		);

		$this->assertSame( 0, $this->uploads->deletes() );
		$this->assertContains( self::BUNDLE . '/assets/media/Photo.JPG', array_keys( $this->bundle_objects() ) );
	}

	/**
	 * A bundle directory cannot be listed, so the manifest is the only record
	 * of what to unlink.
	 */
	public function test_deleting_the_bundle_removes_every_file_the_manifest_names(): void {
		$post_api = $this->make_post_api();

		$this->publish(
			'pull1',
			array(
				'head.html'              => 'head',
				'article.html'           => 'article',
				'assets/media/photo.jpg' => 'binary',
			),
			$post_api
		);
		$this->uploads->reset_counts();

		$post_api->delete_story_bundle( 7, 'aBc123' );

		$this->assertSame( array(), $this->bundle_objects() );
		$this->assertSame( 3, $this->uploads->deletes() );
		$this->assertSame( '', get_post_meta( 7, 'story_manifest', true ) );
	}

	/**
	 * The manifest comes back out of post meta, so a name in it is not trusted
	 * to stay inside the bundle.
	 */
	public function test_deleting_the_bundle_leaves_a_stored_name_that_escapes_it(): void {
		tests_wp_set_post_meta(
			7,
			'story_manifest',
			array(
				'../../8/xYz789/article.html' => array(
					'size' => 7,
					'crc'  => 1,
				),
			)
		);

		$this->make_post_api()->delete_story_bundle( 7, 'aBc123' );

		$this->assertSame( 0, $this->uploads->deletes() );
		$this->assertCount( 1, tests_wp_doing_it_wrong() );
	}

	/**
	 * An interrupted publish must not leave a manifest claiming files were copied.
	 */
	public function test_a_failed_copy_leaves_the_previous_manifest_in_place(): void {
		$this->publish( 'pull1', array( 'article.html' => 'article' ) );

		$stored = get_post_meta( 7, 'story_manifest', true );

		$this->uploads->fail_writes( new \WP_Error( 'file', 'Could not write the story file.' ) );

		$result = $this->publish(
			'pull2',
			array(
				'article.html'           => 'article, edited',
				'assets/media/photo.jpg' => 'binary',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $stored, get_post_meta( 7, 'story_manifest', true ) );
	}

	/**
	 * A file written before the failure is an orphan unless the manifest names
	 * it: `prune()` and `delete()` both work from the manifest and never list
	 * the directory.
	 */
	public function test_a_failed_copy_records_the_files_it_wrote(): void {
		$this->publish( 'pull1', array( 'article.html' => 'article' ) );

		$this->uploads->fail_writes( new \WP_Error( 'file', 'Could not write the story file.' ), 1 );

		$result = $this->publish(
			'pull2',
			array(
				'article.html'           => 'article',
				'assets/media/photo.jpg' => 'binary',
				'assets/theme.css'       => 'body{}',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$names = array_keys( get_post_meta( 7, 'story_manifest', true ) );
		sort( $names );

		$this->assertSame( array( 'article.html', 'assets/media/photo.jpg' ), $names );

		$written = array_keys( $this->bundle_objects() );
		sort( $written );

		$this->assertSame(
			array(
				self::BUNDLE . '/article.html',
				self::BUNDLE . '/assets/media/photo.jpg',
			),
			$written
		);
	}

	/**
	 * PHP stores a digits-only name as an integer key, and a merge that
	 * renumbers keys would record the file under a name it does not have.
	 */
	public function test_a_failed_copy_records_a_digits_only_name_as_written(): void {
		$this->publish( 'pull1', array( 'article.html' => 'article' ) );

		$this->uploads->fail_writes( new \WP_Error( 'file', 'Could not write the story file.' ), 1 );

		$result = $this->publish(
			'pull2',
			array(
				'123'          => 'binary',
				'article.html' => 'article, edited',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$names = array_map( 'strval', array_keys( get_post_meta( 7, 'story_manifest', true ) ) );
		sort( $names );

		$this->assertSame( array( '123', 'article.html' ), $names );
		$this->assertArrayHasKey( self::BUNDLE . '/123', $this->bundle_objects() );
	}

	/**
	 * The manifest is the record of what the bundle holds, so it is stored
	 * once the documents are, not before.
	 */
	public function test_the_manifest_is_stored_after_the_documents(): void {
		$this->publish(
			'pull1',
			array(
				'article.html' => 'article',
				'head.html'    => 'head',
			)
		);

		$order = array();

		foreach ( tests_wp_updated_post_meta() as $call ) {
			if ( in_array( $call['meta_key'], array( 'story_head', 'story_body', 'story_manifest' ), true ) ) {
				$order[] = $call['meta_key'];
			}
		}

		$this->assertSame( array( 'story_head', 'story_body', 'story_manifest' ), $order );
	}

	/**
	 * Without `WP_Filesystem` the chunks cannot be read into staging, and
	 * staging cannot be removed after, so the publish stops before making it.
	 */
	public function test_a_publish_without_a_file_system_leaves_no_staging_directory(): void {
		$this->forgetFileSystemBoot();
		\tests_wp_set_filesystem_available( false );

		$result = $this->publish( 'pull1', array( 'article.html' => 'article' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), glob( $this->temp_root . '/sh_pull_*' ) );
	}

	/**
	 * The story is already in uploads, so a staging directory the host will
	 * not remove fails nothing. Its name is random and recorded nowhere, so
	 * this is the one chance to say it was left.
	 */
	public function test_a_staging_directory_that_cannot_be_removed_is_reported(): void {
		\tests_wp_set_delete_failure();

		$result = $this->publish( 'pull1', array( 'article.html' => 'article' ) );

		$this->assertNull( $result );

		$left    = glob( $this->temp_root . '/sh_pull_*' );
		$reports = tests_wp_doing_it_wrong();

		$this->assertCount( 1, $left );
		$this->assertCount( 1, $reports );
		$this->assertStringContainsString( $left[0], $reports[0]['message'] );
	}

	public function test_an_unusable_story_id_reaches_no_uploads_call(): void {
		$result = $this->make_post_api()->publish_story_bundle( 7, '../../etc', 'pull1', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'story_id', $result->get_error_codes() );
		$this->assertSame( 0, $this->uploads->writes() );
		$this->assertSame( 0, $this->uploads->make_dir_calls() );
	}

	/**
	 * PLA-2720. An entry name is a path segment of the bundle, and the archive
	 * comes from outside, so a name that escapes ends the publish before a
	 * directory is created for it.
	 */
	public function test_an_archive_entry_that_escapes_the_bundle_publishes_nothing(): void {
		$result = $this->publish( 'pull1', array( 'x/../../escaped.txt' => 'payload' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->bundle_objects() );
		$this->assertSame( 0, $this->uploads->writes() );
		$this->assertSame( 0, $this->uploads->make_dir_calls() );
	}

	public function test_an_archive_with_two_names_that_fold_to_one_publishes_nothing(): void {
		$result = $this->publish(
			'pull1',
			array(
				'assets/media/Photo.JPG' => 'first',
				'assets/media/photo.jpg' => 'second',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->bundle_objects() );
		$this->assertSame( 0, $this->uploads->writes() );
	}

	public function test_an_archive_with_two_names_for_one_path_publishes_nothing(): void {
		$result = $this->publish(
			'pull1',
			array(
				'assets/theme.css'   => 'first',
				'assets/./theme.css' => 'second',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->bundle_objects() );
		$this->assertSame( 0, $this->uploads->writes() );
	}

	/**
	 * The bundle's files, without the chunks the download left in uploads.
	 *
	 * Chunks are removed by `pull_story_cleanup()`, one step further out than
	 * these tests reach.
	 *
	 * @return array<string, string>
	 */
	private function bundle_objects(): array {
		$objects = array();

		foreach ( $this->uploads->objects() as $path => $contents ) {
			if ( 0 === strpos( $path, self::BUNDLE . '/' ) ) {
				$objects[ $path ] = $contents;
			}
		}

		return $objects;
	}

	/**
	 * Publishes an archive as the single chunk of one download.
	 *
	 * @param string                $nonce    Request nonce of the pull.
	 * @param array<string, string> $entries  Archive contents.
	 * @param \Shorthand\Services\PostAPI|null $post_api Instance to publish through.
	 */
	private function publish( string $nonce, array $entries, ?PostAPI $post_api = null ): ?\WP_Error {
		$archive = $this->make_archive( $entries );

		$bundle = ( new BundleStore( $this->uploads ) )->open( 7, 'aBc123' );

		$this->assertNotNull( $bundle );

		/* The chunk is seeded where the download would have written it. */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->uploads->put( $bundle->download( $nonce )->chunk_path( 0 ), file_get_contents( $archive ) );

		$post_api = $post_api ?? $this->make_post_api();

		return $post_api->publish_story_bundle( 7, 'aBc123', $nonce, 1 );
	}

	/**
	 * The featured image follows the content. A cover that cannot be imported
	 * must not fail the publish, so `sync()` returns a state, never an error.
	 */
	public function test_the_story_cover_is_synced_after_the_content_is_stored(): void {
		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->expects( $this->once() )
			->method( 'sync' )
			->with( 7, 'aBc123' )
			->willReturnCallback(
				function (): string {
					$this->assertSame( '<h1>Story</h1>', \get_post_meta( 7, 'story_body', true ) );
					return StoryCover::OUTCOME_FAILED;
				}
			);

		$result = $this->publish(
			'pull1',
			array(
				'head.html'    => '',
				'article.html' => '<h1>Story</h1>',
			),
			$this->make_post_api( $story_cover )
		);

		$this->assertNull( $result );
	}

	private function make_post_api( ?StoryCover $story_cover = null ): PostAPI {
		$options = $this->createMock( Options::class );
		$options->method( 'get_post_regex_list' )->willReturn( '' );

		$transformer = $this->createMock( StoryContentTransformer::class );
		$transformer->method( 'rewrite_story_bundle_paths' )->willReturnCallback(
			static function ( string $bundle_url, string $markup ): string {
				return $markup;
			}
		);
		$transformer->method( 'apply_processing_rule_set' )->willReturnCallback(
			static function ( string $head, string $article ): array {
				return array(
					'head'    => $head,
					'article' => $article,
				);
			}
		);

		return new PostAPI(
			$this->createMock( Shorthand::class ),
			$options,
			$this->createMock( Permissions::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class ),
			$transformer,
			new BundleStore( $this->uploads ),
			new StoryTextExtractor(),
			$story_cover ?? $this->createMock( StoryCover::class )
		);
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function make_archive( array $entries ): string {
		$path = $this->temp_root . '/archive.zip';

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( (string) $name, $contents );
		}

		$zip->close();

		return $path;
	}

	private function remove_tree( string $path ): void {
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
}
