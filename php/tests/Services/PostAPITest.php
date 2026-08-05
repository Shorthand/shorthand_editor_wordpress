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

	private function make_post_api( Shorthand $shorthand, ?AuthStateManager $auth_state_manager = null ): PostAPI {
		return new PostAPI(
			$shorthand,
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth_state_manager ?? $this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class )
		);
	}

	private function make_connected_auth_state_manager(): AuthStateManager {
		$auth_state_manager = $this->createMock( AuthStateManager::class );
		$auth_state_manager->method( 'is_connected' )->willReturn( true );
		return $auth_state_manager;
	}

	public function test_connect_story_creates_a_draft_post_by_default(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn(
			array(
				'meta' => array(
					'title'       => 'My Story',
					'description' => 'A description',
				),
			)
		);
		$shorthand->expects( $this->once() )->method( 'set_story_external_id' )->with( 'story-1', 123 )->willReturn( true );

		tests_wp_set_insert_post_result( 123 );
		tests_wp_set_post( 123, (object) array( 'ID' => 123 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post     = $post_api->connect_story( 'story-1', null );

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
		$post_api->connect_story( 'story-1', null );

		$updated_meta = tests_wp_updated_post_meta();
		$this->assertCount( 1, $updated_meta );
		$this->assertSame( 123, $updated_meta[0]['post_id'] );
		$this->assertSame( 'story_id', $updated_meta[0]['meta_key'] );
		$this->assertSame( 'story-1', $updated_meta[0]['meta_value'] );
	}

	public function test_connect_story_respects_an_explicit_post_status(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->willReturn( array( 'meta' => array( 'title' => 'My Story' ) ) );
		$shorthand->method( 'set_story_external_id' )->willReturn( true );

		tests_wp_set_insert_post_result( 55 );
		tests_wp_set_post( 55, (object) array( 'ID' => 55 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post_api->connect_story( 'story-1', null, 'draft' );

		$inserted = tests_wp_inserted_posts();
		$this->assertSame( 'draft', $inserted[0]['post_status'] );
	}

	public function test_pull_story_begin_maps_a_429_from_the_generate_route_to_a_rate_limited_error(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 429 ),
				'body'     => '',
			)
		);

		tests_wp_set_post_meta( 123, 'story_id', 'story-1' );

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

		tests_wp_set_post_meta( 123, 'story_id', 'story-1' );

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

		tests_wp_set_post_meta( 123, 'story_id', 'story-1' );

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
