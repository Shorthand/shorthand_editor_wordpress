<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Tests\WordPressTestCase;

final class PostAPITest extends WordPressTestCase {

	private function make_post_api( Shorthand $shorthand ): PostAPI {
		return new PostAPI(
			$shorthand,
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class )
		);
	}

	public function test_connect_story_creates_a_draft_post_by_default(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->with( 'aBc123' )->willReturn(
			array(
				'meta' => array(
					'title'       => 'My Story',
					'description' => 'A description',
				),
			)
		);
		$shorthand->expects( $this->once() )->method( 'set_story_external_id' )->with( 'aBc123', 123 )->willReturn( true );

		tests_wp_set_insert_post_result( 123 );
		tests_wp_set_post( 123, (object) array( 'ID' => 123 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post     = $post_api->connect_story( 'aBc123', null );

		$this->assertSame( 123, $post->ID );

		$inserted = tests_wp_inserted_posts();
		$this->assertCount( 1, $inserted );
		$this->assertSame( 'draft', $inserted[0]['post_status'] );
		$this->assertSame( 'My Story', $inserted[0]['post_title'] );
		$this->assertSame( 'tse_story', $inserted[0]['post_type'] );
	}

	public function test_connect_story_links_the_new_post_to_the_story_via_meta(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->willReturn( array( 'meta' => array( 'title' => 'My Story' ) ) );
		$shorthand->method( 'set_story_external_id' )->willReturn( true );

		tests_wp_set_insert_post_result( 123 );
		tests_wp_set_post( 123, (object) array( 'ID' => 123 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post_api->connect_story( 'aBc123', null );

		$updated_meta = tests_wp_updated_post_meta();
		$this->assertCount( 1, $updated_meta );
		$this->assertSame( 123, $updated_meta[0]['post_id'] );
		$this->assertSame( 'story_id', $updated_meta[0]['meta_key'] );
		$this->assertSame( 'aBc123', $updated_meta[0]['meta_value'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function path_shaped_story_ids(): array {
		return array(
			'parent directory' => array( '../../wp-content' ),
			'absolute path'    => array( '/etc/passwd' ),
			'backslash'        => array( 'abc\\def' ),
			'null byte'        => array( "abc\0def" ),
		);
	}

	/**
	 * @dataProvider path_shaped_story_ids
	 */
	public function test_connect_story_refuses_a_story_id_that_is_not_a_path_segment( string $story_id ): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->never() )->method( 'get_story_settings' );
		$shorthand->expects( $this->never() )->method( 'set_story_external_id' );

		$post_api = $this->make_post_api( $shorthand );

		$this->expectException( \Tests_WP_Die_Exception::class );

		try {
			$post_api->connect_story( $story_id, null );
		} finally {
			$this->assertSame( array(), tests_wp_inserted_posts(), 'No post may be created for an unusable story ID.' );
			$this->assertSame( array(), tests_wp_updated_post_meta() );
		}
	}

	/**
	 * @dataProvider path_shaped_story_ids
	 */
	public function test_bundle_paths_are_withheld_for_a_story_id_that_is_not_a_path_segment( string $story_id ): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->assertNull( $post_api->get_story_bundle_path( 7, $story_id ) );
		$this->assertNull( $post_api->get_story_bundle_url( 7, $story_id ) );
	}

	public function test_bundle_paths_keep_the_case_of_the_story_id(): void {
		tests_wp_set_upload_dir( '/uploads', 'https://example.test/uploads' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->assertSame( '/uploads/shorthand/7/aBc123', $post_api->get_story_bundle_path( 7, 'aBc123' ) );
		$this->assertSame( 'https://example.test/uploads/shorthand/7/aBc123', $post_api->get_story_bundle_url( 7, 'aBc123' ) );
	}

	/**
	 * Deleting a post whose stored story ID is unusable must delete nothing,
	 * and must not reach the file system at all.
	 *
	 * @dataProvider path_shaped_story_ids
	 */
	public function test_delete_story_bundle_does_nothing_for_a_story_id_that_is_not_a_path_segment( string $story_id ): void {
		$this->assertFalse(
			function_exists( 'wp_raise_memory_limit' ),
			'FileSystem::init() is unavailable under test, so reaching it would raise an Error.'
		);

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );
		$post_api->delete_story_bundle( 7, $story_id );

		$this->assertSame( array(), tests_wp_deleted_files() );
	}

	public function test_connect_story_respects_an_explicit_post_status(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->willReturn( array( 'meta' => array( 'title' => 'My Story' ) ) );
		$shorthand->method( 'set_story_external_id' )->willReturn( true );

		tests_wp_set_insert_post_result( 55 );
		tests_wp_set_post( 55, (object) array( 'ID' => 55 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post_api->connect_story( 'aBc123', null, 'draft' );

		$inserted = tests_wp_inserted_posts();
		$this->assertSame( 'draft', $inserted[0]['post_status'] );
	}
}
