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
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Tests\WordPressTestCase;

final class PostAPITest extends WordPressTestCase {

	private const STORY = '<div class="Theme-Story">
		<section class="Theme-Section Theme-TitleSection">
			<h1 class="Theme-StoryTitle">The Long Road</h1>
			<div class="Theme-LeadIn">A journey through the hills.</div>
			<div class="Theme-Byline">By Alex Rivers</div>
		</section>
		<section class="Theme-Section">
			<div class="Theme-Layer-BodyText"><p>First paragraph of the story.</p></div>
		</section>
		<footer class="Theme-Footer">Built with Shorthand</footer>
	</div>';

	private function store_text( PostAPI $post_api, int $post_id, string $article ): void {
		$this->callPrivateMethod( $post_api, 'store_story_text', array( $post_id, $article ) );
	}

	private function make_post_api( Shorthand $shorthand, ?AuthStateManager $auth_state_manager = null, ?FileSystemService $file_system = null ): PostAPI {
		return new PostAPI(
			$shorthand,
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth_state_manager ?? $this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class ),
			$file_system ?? $this->createMock( FileSystemService::class ),
			new StoryTextExtractor()
		);
	}

	private function make_connected_auth_state_manager(): AuthStateManager {
		$auth_state_manager = $this->createMock( AuthStateManager::class );
		$auth_state_manager->method( 'is_connected' )->willReturn( true );
		return $auth_state_manager;
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
		$file_system = $this->createMock( FileSystemService::class );
		$file_system->expects( $this->never() )->method( 'delete_tree' );
		$file_system->expects( $this->never() )->method( 'delete_dir' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ), null, $file_system );
		$post_api->delete_story_bundle( 7, $story_id );
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

	public function test_store_story_text_puts_searchable_prose_in_post_content(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertCount( 1, $updated );
		$this->assertSame( 42, $updated[0]['ID'] );

		$content = stripslashes( $updated[0]['post_content'] );
		$this->assertStringNotContainsString( 'The Long Road', $content );
		$this->assertStringNotContainsString( 'Built with Shorthand', $content );
		$this->assertStringContainsString( 'By Alex Rivers', $content );
		$this->assertStringContainsString( 'First paragraph of the story.', $content );
	}

	public function test_store_story_text_keeps_the_title_section_out_of_the_excerpt(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertSame( 'First paragraph of the story.', stripslashes( $updated[0]['post_excerpt'] ) );
	}

	public function test_store_story_text_records_the_excerpt_it_wrote(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, self::STORY );

		$this->assertSame(
			(string) get_post_field( 'post_excerpt', 42, 'raw' ),
			(string) get_post_meta( 42, 'story_excerpt', true )
		);
	}

	public function test_store_story_text_leaves_an_author_written_excerpt_alone(): void {
		tests_wp_set_post_field( 42, 'post_excerpt', 'A hand-written summary.' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );
		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertArrayNotHasKey( 'post_excerpt', $updated[0] );
		$this->assertArrayHasKey( 'post_content', $updated[0] );
	}

	/**
	 * An author who empties the excerpt means it, so it is not refilled.
	 */
	public function test_store_story_text_leaves_a_cleared_excerpt_empty(): void {
		tests_wp_set_post_field( 42, 'post_excerpt', '' );
		tests_wp_set_post_meta( 42, 'story_excerpt', 'An earlier draft of the story.' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );
		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertArrayNotHasKey( 'post_excerpt', $updated[0] );
		$this->assertArrayHasKey( 'post_content', $updated[0] );
	}

	public function test_store_story_text_replaces_an_excerpt_it_wrote_before(): void {
		tests_wp_set_post_field( 42, 'post_excerpt', 'An earlier draft of the story.' );
		tests_wp_set_post_meta( 42, 'story_excerpt', 'An earlier draft of the story.' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );
		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertSame( 'First paragraph of the story.', stripslashes( $updated[0]['post_excerpt'] ) );
	}

	public function test_store_story_text_writes_nothing_for_an_empty_body(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, '   ' );

		$this->assertSame( array(), tests_wp_updated_posts() );
	}

	public function test_store_story_text_flags_its_own_write_so_publishing_does_not_recurse(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$flag_during_write = null;
		tests_wp_set_update_post_observer(
			static function () use ( $post_api, &$flag_during_write ): void {
				$flag_during_write = $post_api->is_storing_text();
			}
		);

		$this->assertFalse( $post_api->is_storing_text() );

		$this->store_text( $post_api, 42, self::STORY );

		$this->assertTrue( $flag_during_write );
		$this->assertFalse( $post_api->is_storing_text() );
	}

	public function test_pull_story_begin_maps_a_429_from_the_generate_route_to_a_rate_limited_error(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 429 ),
				'body'     => '',
			)
		);

		tests_wp_set_post_meta( 123, 'story_id', 'story1' );

		$post_api = $this->make_post_api( $shorthand, $this->make_connected_auth_state_manager() );
		$result   = $post_api->pull_story_begin( 123 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame(
			'Your Shorthand workspace is publishing too many stories at once. Wait a moment, then publish again.',
			$result->get_error_message( 'pretty' )
		);
		$this->assertSame( 429, $result->get_error_data( 'status' ) );
	}

	public function test_pull_story_begin_keeps_the_plugin_message_first_when_the_api_supplies_its_own(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 429 ),
				'body'     => wp_json_encode(
					array(
						'message' => 'This workspace already has 10 story downloads in progress, try again in a moment.',
					)
				),
			)
		);

		tests_wp_set_post_meta( 123, 'story_id', 'story1' );

		$post_api = $this->make_post_api( $shorthand, $this->make_connected_auth_state_manager() );
		$result   = $post_api->pull_story_begin( 123 );

		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame(
			'Your Shorthand workspace is publishing too many stories at once. Wait a moment, then publish again.',
			$result->get_error_message( 'pretty' )
		);
	}

	public function test_pull_story_begin_maps_other_generate_failures_to_a_story_error(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '',
			)
		);

		tests_wp_set_post_meta( 123, 'story_id', 'story1' );

		$post_api = $this->make_post_api( $shorthand, $this->make_connected_auth_state_manager() );
		$result   = $post_api->pull_story_begin( 123 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'story', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data( 'status' ) );
	}

	public function test_get_restore_status_returns_draft_for_a_first_time_publish(): void {
		$this->assertSame( 'draft', PostAPI::get_restore_status( 'auto-draft' ) );
		$this->assertSame( 'draft', PostAPI::get_restore_status( false ) );
		$this->assertSame( 'draft', PostAPI::get_restore_status( '' ) );
		$this->assertSame( 'draft', PostAPI::get_restore_status( 'draft' ) );
	}

	public function test_get_restore_status_preserves_the_status_of_an_already_published_story(): void {
		$this->assertSame( 'publish', PostAPI::get_restore_status( 'publish' ) );
		$this->assertSame( 'future', PostAPI::get_restore_status( 'future' ) );
		$this->assertSame( 'private', PostAPI::get_restore_status( 'private' ) );
	}
}
