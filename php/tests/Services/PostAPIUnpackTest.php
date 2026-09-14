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
				self::BUNDLE . '/assets/media/photo.jpg'  => 'binary',
				self::BUNDLE . '/assets/theme.css'        => 'body{}',
				self::BUNDLE . '/docs/pull1/article.html' => '<h1>Story</h1>',
				self::BUNDLE . '/docs/pull1/head.html'    => '<link rel="stylesheet" href="assets/theme.css">',
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
			array( self::BUNDLE . '/docs/pull1/article.html' ),
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
	 *
	 * The two documents move to a new directory on every publish, so they are
	 * the floor, not zero. Media is what makes a bundle large, and media that
	 * did not change is not touched.
	 */
	public function test_a_republish_with_no_change_rewrites_only_the_documents(): void {
		$entries = array(
			'head.html'              => 'head',
			'article.html'           => 'article',
			'assets/media/photo.jpg' => 'binary',
		);

		$this->publish( 'pull1', $entries );
		$this->uploads->reset_counts();

		$this->publish( 'pull2', $entries );

		$this->assertSame( 2, $this->uploads->writes() );

		$this->assertSame(
			array(
				self::BUNDLE . '/assets/media/photo.jpg'  => 'binary',
				self::BUNDLE . '/docs/pull2/article.html' => 'article',
				self::BUNDLE . '/docs/pull2/head.html'    => 'head',
			),
			$this->bundle_objects()
		);
	}

	/**
	 * A publish must not write a path it has already written with this content.
	 */
	public function test_the_documents_land_on_a_new_path_every_publish(): void {
		$entries = array(
			'head.html'    => 'head',
			'article.html' => 'article',
		);

		$this->publish( 'pull1', $entries );
		$this->publish( 'pull2', $entries );
		$this->publish( 'pull3', $entries );

		$this->assertSame(
			array(
				self::BUNDLE . '/docs/pull3/article.html',
				self::BUNDLE . '/docs/pull3/head.html',
			),
			array_keys( $this->bundle_objects() )
		);
		$this->assertSame( 6, $this->uploads->writes() );
	}

	/**
	 * The document path is a public extension point, so it names where the
	 * document actually is.
	 */
	public function test_the_post_processing_filters_receive_the_versioned_document_paths(): void {
		$this->publish(
			'pull1',
			array(
				'head.html'    => 'head',
				'article.html' => 'article',
			)
		);

		$this->assertSame(
			array( array( self::BUNDLE, self::BUNDLE . '/docs/pull1/article.html' ) ),
			tests_wp_get_filter_args( 'theshed_post_process_body' )
		);
		$this->assertSame(
			array( array( self::BUNDLE, self::BUNDLE . '/docs/pull1/head.html' ) ),
			tests_wp_get_filter_args( 'theshed_post_process_head' )
		);
	}

	/**
	 * A nonce is interpolated into a path, so it is validated like a story ID.
	 */
	public function test_an_unusable_nonce_leaves_the_documents_at_the_bundle_root(): void {
		$this->publish( '../../etc', array( 'article.html' => 'article' ) );

		$this->assertSame(
			array( self::BUNDLE . '/article.html' ),
			array_keys( $this->bundle_objects() )
		);
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

		$this->assertSame( 3, $this->uploads->writes() );
		$this->assertSame( 'article, edited', $objects[ self::BUNDLE . '/docs/pull2/article.html' ] );
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

		/* The departed asset, and the document of the previous publish. */
		$this->assertSame( 2, $this->uploads->deletes() );
		$this->assertSame(
			array( self::BUNDLE . '/docs/pull2/article.html' ),
			array_keys( $this->bundle_objects() )
		);
		$this->assertSame( array( 'docs/pull2/article.html' ), array_keys( get_post_meta( 7, 'story_manifest', true ) ) );
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

		/* Only the document of the previous publish. */
		$this->assertSame( 1, $this->uploads->deletes() );
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
				'article.html'           => 'article, edited',
				'assets/media/photo.jpg' => 'binary',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$names = array_keys( get_post_meta( 7, 'story_manifest', true ) );
		sort( $names );

		$this->assertSame( array( 'assets/media/photo.jpg', 'docs/pull1/article.html' ), $names );

		$written = array_keys( $this->bundle_objects() );
		sort( $written );

		$this->assertSame(
			array(
				self::BUNDLE . '/assets/media/photo.jpg',
				self::BUNDLE . '/docs/pull1/article.html',
			),
			$written
		);
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->uploads->put( self::BUNDLE . '_' . $nonce . '_0.part', file_get_contents( $archive ) );

		$post_api = $post_api ?? $this->make_post_api();

		return $post_api->publish_story_bundle( 7, 'aBc123', $nonce, 1 );
	}

	private function make_post_api(): PostAPI {
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
			new StoryTextExtractor()
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
			$zip->addFromString( $name, $contents );
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
