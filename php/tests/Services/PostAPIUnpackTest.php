<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\FileSystemService;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;
use ZipArchive;

/**
 * Unpacking a story archive where the uploads directory is an object store.
 *
 * `ZipArchive::extractTo()` uses native syscalls, so the only way this works
 * is if it never targets uploads.
 */
final class PostAPIUnpackTest extends WordPressTestCase {

	/** @var string */
	private $temp_root;

	/** @var string */
	private $staging_path;

	/** @var \Shorthand\Tests\Support\FakeRemoteFileSystem */
	private $file_system;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );

		$this->temp_root = sys_get_temp_dir() . '/sh_unpack_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
		tests_wp_set_temp_dir( $this->temp_root );

		$this->staging_path = $this->temp_root . '/sh_pull_1';
		mkdir( $this->staging_path, 0777, true );

		$this->file_system = new FakeRemoteFileSystem();
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->temp_root );

		parent::tearDown();
	}

	public function test_the_archive_is_unpacked_under_staging_and_copied_into_the_bundle(): void {
		$archive = $this->make_archive(
			array(
				'head.html'              => '<link rel="stylesheet" href="assets/theme.css">',
				'article.html'           => '<h1>Story</h1>',
				'assets/theme.css'       => 'body{}',
				'assets/media/photo.jpg' => 'binary',
			)
		);

		$result = $this->make_post_api()->extract_story_content( $archive, 7, 'aBc123', $this->staging_path, 'pull1' );

		$this->assertNull( $result );

		$bundle = 'vip://wp-content/uploads/shorthand/7/aBc123';

		$this->assertSame(
			array(
				$bundle . '/assets/media/photo.jpg'  => 'binary',
				$bundle . '/assets/theme.css'        => 'body{}',
				$bundle . '/docs/pull1/article.html' => '<h1>Story</h1>',
				$bundle . '/docs/pull1/head.html'    => '<link rel="stylesheet" href="assets/theme.css">',
			),
			$this->file_system->objects()
		);
		$this->assertSame( 4, $this->file_system->writes() );
	}

	/**
	 * The unpacked tree is local, so `extractTo()` has a real directory to write to.
	 */
	public function test_the_unpacked_tree_lands_in_the_staging_directory(): void {
		$archive = $this->make_archive(
			array(
				'head.html'    => 'head',
				'article.html' => 'article',
			)
		);

		$this->make_post_api()->extract_story_content( $archive, 7, 'aBc123', $this->staging_path, 'pull1' );

		$this->assertFileExists( $this->staging_path . '/unpacked/article.html' );
	}

	public function test_the_two_documents_are_stored_as_post_meta(): void {
		$archive = $this->make_archive(
			array(
				'head.html'    => 'head markup',
				'article.html' => 'article markup',
			)
		);

		$this->make_post_api()->extract_story_content( $archive, 7, 'aBc123', $this->staging_path, 'pull1' );

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

		$this->make_post_api()->extract_story_content( $this->make_archive( $entries ), 7, 'aBc123', $this->staging_path, 'pull1' );
		$this->file_system->reset_counts();

		$this->make_post_api()->extract_story_content( $this->make_archive( $entries ), 7, 'aBc123', $this->staging_path, 'pull2' );

		$this->assertSame( 2, $this->file_system->writes() );
		$this->assertSame( 2, $this->file_system->deletes() );

		$bundle = 'vip://wp-content/uploads/shorthand/7/aBc123';

		$this->assertSame(
			array(
				$bundle . '/assets/media/photo.jpg'  => 'binary',
				$bundle . '/docs/pull2/article.html' => 'article',
				$bundle . '/docs/pull2/head.html'    => 'head',
			),
			$this->file_system->objects()
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

		$this->make_post_api()->extract_story_content( $this->make_archive( $entries ), 7, 'aBc123', $this->staging_path, 'pull1' );
		$this->make_post_api()->extract_story_content( $this->make_archive( $entries ), 7, 'aBc123', $this->staging_path, 'pull2' );
		$this->make_post_api()->extract_story_content( $this->make_archive( $entries ), 7, 'aBc123', $this->staging_path, 'pull3' );

		$bundle = 'vip://wp-content/uploads/shorthand/7/aBc123';

		$this->assertSame(
			array(
				$bundle . '/docs/pull3/article.html',
				$bundle . '/docs/pull3/head.html',
			),
			array_keys( $this->file_system->objects() )
		);
		$this->assertSame( 6, $this->file_system->writes() );
	}

	/**
	 * The document path is a public extension point, so it names where the
	 * document actually is.
	 */
	public function test_the_post_processing_filters_receive_the_versioned_document_paths(): void {
		$archive = $this->make_archive(
			array(
				'head.html'    => 'head',
				'article.html' => 'article',
			)
		);

		$this->make_post_api()->extract_story_content( $archive, 7, 'aBc123', $this->staging_path, 'pull1' );

		$bundle = 'vip://wp-content/uploads/shorthand/7/aBc123';

		$this->assertSame(
			array( array( $bundle, $bundle . '/docs/pull1/article.html' ) ),
			tests_wp_get_filter_args( 'theshed_post_process_body' )
		);
		$this->assertSame(
			array( array( $bundle, $bundle . '/docs/pull1/head.html' ) ),
			tests_wp_get_filter_args( 'theshed_post_process_head' )
		);
	}

	/**
	 * A nonce is interpolated into a path, so it is validated like a story ID.
	 */
	public function test_an_unusable_nonce_leaves_the_documents_at_the_bundle_root(): void {
		$archive = $this->make_archive( array( 'article.html' => 'article' ) );

		$this->make_post_api()->extract_story_content( $archive, 7, 'aBc123', $this->staging_path, '../../etc' );

		$this->assertSame(
			array( 'vip://wp-content/uploads/shorthand/7/aBc123/article.html' ),
			array_keys( $this->file_system->objects() )
		);
	}

	public function test_a_republish_writes_only_the_files_that_changed(): void {
		$this->make_post_api()->extract_story_content(
			$this->make_archive(
				array(
					'head.html'              => 'head',
					'article.html'           => 'article',
					'assets/media/photo.jpg' => 'binary',
				)
			),
			7,
			'aBc123',
			$this->staging_path,
			'pull1'
		);
		$this->file_system->reset_counts();

		$this->make_post_api()->extract_story_content(
			$this->make_archive(
				array(
					'head.html'              => 'head',
					'article.html'           => 'article, edited',
					'assets/media/photo.jpg' => 'binary, edited',
				)
			),
			7,
			'aBc123',
			$this->staging_path,
			'pull2'
		);

		$objects = $this->file_system->objects();

		$this->assertSame( 3, $this->file_system->writes() );
		$this->assertSame( 'article, edited', $objects['vip://wp-content/uploads/shorthand/7/aBc123/docs/pull2/article.html'] );
		$this->assertSame( 'binary, edited', $objects['vip://wp-content/uploads/shorthand/7/aBc123/assets/media/photo.jpg'] );
	}

	public function test_a_file_that_left_the_story_is_deleted_from_the_bundle(): void {
		$this->make_post_api()->extract_story_content(
			$this->make_archive(
				array(
					'article.html'         => 'article',
					'assets/media/old.jpg' => 'binary',
				)
			),
			7,
			'aBc123',
			$this->staging_path,
			'pull1'
		);
		$this->file_system->reset_counts();

		$this->make_post_api()->extract_story_content(
			$this->make_archive( array( 'article.html' => 'article' ) ),
			7,
			'aBc123',
			$this->staging_path,
			'pull2'
		);

		/* The departed asset, and the documents of the previous publish. */
		$this->assertSame( 2, $this->file_system->deletes() );
		$this->assertSame(
			array( 'vip://wp-content/uploads/shorthand/7/aBc123/docs/pull2/article.html' ),
			array_keys( $this->file_system->objects() )
		);
		$this->assertSame( array( 'docs/pull2/article.html' ), array_keys( get_post_meta( 7, 'story_manifest', true ) ) );
	}

	/**
	 * A bundle directory cannot be listed, so the manifest is the only record
	 * of what to unlink.
	 */
	public function test_deleting_the_bundle_removes_every_file_the_manifest_names(): void {
		$post_api = $this->make_post_api();

		$post_api->extract_story_content(
			$this->make_archive(
				array(
					'head.html'              => 'head',
					'article.html'           => 'article',
					'assets/media/photo.jpg' => 'binary',
				)
			),
			7,
			'aBc123',
			$this->staging_path,
			'pull1'
		);
		$this->file_system->reset_counts();

		$post_api->delete_story_bundle( 7, 'aBc123' );

		$this->assertSame( array(), $this->file_system->objects() );
		$this->assertSame( 3, $this->file_system->deletes() );
		$this->assertSame( '', get_post_meta( 7, 'story_manifest', true ) );
	}

	/**
	 * An interrupted publish must not leave a manifest claiming files were copied.
	 */
	public function test_a_failed_copy_leaves_the_previous_manifest_in_place(): void {
		$post_api = $this->make_post_api();

		$post_api->extract_story_content(
			$this->make_archive( array( 'article.html' => 'article' ) ),
			7,
			'aBc123',
			$this->staging_path,
			'pull1'
		);

		$stored = get_post_meta( 7, 'story_manifest', true );

		$failing = $this->createMock( FileSystemService::class );
		$failing->method( 'copy_tree' )->willReturn( new \WP_Error( 'file', 'Could not write the story file.' ) );

		$result = $this->make_post_api( $failing )->extract_story_content(
			$this->make_archive(
				array(
					'article.html'           => 'article, edited',
					'assets/media/photo.jpg' => 'binary',
				)
			),
			7,
			'aBc123',
			$this->staging_path,
			'pull2'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $stored, get_post_meta( 7, 'story_manifest', true ) );
	}

	public function test_an_unusable_story_id_reaches_no_file_system_call(): void {
		$archive = $this->make_archive( array( 'article.html' => 'article' ) );

		$file_system = $this->createMock( FileSystemService::class );
		$file_system->expects( $this->never() )->method( 'make_dir' );
		$file_system->expects( $this->never() )->method( 'copy_tree' );

		$result = $this->make_post_api( $file_system )->extract_story_content( $archive, 7, '../../etc', $this->staging_path, 'pull1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'story_id', $result->get_error_codes() );
	}

	private function make_post_api( ?FileSystemService $file_system = null ): PostAPI {
		$options = $this->createMock( Options::class );
		$options->method( 'is_staging_enabled' )->willReturn( true );
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
			$file_system ?? $this->file_system
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
