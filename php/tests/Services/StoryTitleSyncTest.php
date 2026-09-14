<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Loader;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryTitleSync;
use Shorthand\Tests\WordPressTestCase;

final class StoryTitleSyncTest extends WordPressTestCase {

	public function test_push_sends_the_title_and_clears_any_held_change_when_connected(): void {
		\tests_wp_set_post_meta( 7, StoryTitleSync::META_KEY, 'Older held title' );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->once() )->method( 'set_story_title' )->with( 'abc123', 'New title' )->willReturn( null );

		$this->make_sync( $shorthand, true )->push( 7, 'abc123', 'New title' );

		$this->assertSame( '', get_post_meta( 7, StoryTitleSync::META_KEY, true ) );
	}

	public function test_push_holds_the_title_when_disconnected(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->never() )->method( 'set_story_title' );

		$sync = $this->make_sync( $shorthand, false );
		$sync->push( 7, 'abc123', 'New title' );

		$this->assertSame( 'New title', $sync->get_pending( 7 ) );
	}

	public function test_push_holds_the_title_when_shorthand_refuses_it(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'set_story_title' )->willReturn( new \WP_Error( 'status', 'Received HTTP status code 500.' ) );

		$sync = $this->make_sync( $shorthand, true );
		$sync->push( 7, 'abc123', 'New title' );

		$this->assertSame( 'New title', $sync->get_pending( 7 ) );
	}

	public function test_get_pending_is_null_when_nothing_is_held(): void {
		$this->assertNull( $this->make_sync( $this->createMock( Shorthand::class ), true )->get_pending( 7 ) );
	}

	public function test_sweep_pushes_the_current_title_of_every_post_with_a_held_change(): void {
		\tests_wp_set_posts_query_result( array( 7, 9 ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );
		\tests_wp_set_post_meta( 7, StoryTitleSync::META_KEY, 'Held' );
		\tests_wp_set_post_field( 7, 'post_title', 'Current title' );
		\tests_wp_set_post_meta( 9, StoryTitleSync::META_KEY, 'Orphan' );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->once() )->method( 'set_story_title' )->with( 'abc123', 'Current title' )->willReturn( null );

		$sync = $this->make_sync( $shorthand, true );
		$sync->sweep();

		$query = \tests_wp_posts_queries()[0];
		$this->assertSame( 'tse_story', $query['post_type'] );
		$this->assertSame( StoryTitleSync::META_KEY, $query['meta_key'] );
		$this->assertNull( $sync->get_pending( 7 ) );
		$this->assertNull( $sync->get_pending( 9 ), 'A post with no story drops its held title' );
	}

	public function test_a_connection_returning_runs_the_sweep(): void {
		\tests_wp_set_posts_query_result( array() );

		$loader = new Loader();
		$this->make_sync( $this->createMock( Shorthand::class ), true )->init( $loader );
		$loader->register();

		\do_action( 'shorthand_auth_state_changed', AuthStateManager::STATE_CONNECTED, AuthStateManager::STATE_DISCONNECTED );
		\do_action( 'shorthand_auth_state_changed', AuthStateManager::STATE_INVALID, AuthStateManager::STATE_CONNECTED );

		$this->assertCount( 1, \tests_wp_posts_queries() );
	}

	private function make_sync( Shorthand $shorthand, bool $connected ): StoryTitleSync {
		$auth = $this->createMock( AuthStateManager::class );
		$auth->method( 'is_connected' )->willReturn( $connected );

		return new StoryTitleSync( $shorthand, $auth, 'tse_story' );
	}
}
