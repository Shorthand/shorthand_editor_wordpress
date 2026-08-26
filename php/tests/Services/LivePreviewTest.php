<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
use Shorthand\Plugin\Dependencies;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\LivePreview;
use Shorthand\Services\PostAPI;
use Shorthand\Services\StoryPreview;
use Shorthand\Tests\WordPressTestCase;

final class LivePreviewTest extends WordPressTestCase {

	private const POST_TYPE = 'tse_story';
	private const POST_ID   = 42;

	public function test_a_recognised_preview_serves_live_story_content(): void {
		$live_preview = $this->resolve_on_preview_request();

		$this->assertSame( '<article>live</article>', $live_preview->filter_meta( null, self::POST_ID, 'story_body', true ) );
		$this->assertSame( '<meta name="live">', $live_preview->filter_meta( null, self::POST_ID, 'story_head', true ) );
		$this->assertSame( 9, $live_preview->filter_meta( null, self::POST_ID, 'story_version', true ) );
	}

	public function test_live_content_is_wrapped_when_a_single_value_was_not_asked_for(): void {
		$live_preview = $this->resolve_on_preview_request();

		$this->assertSame(
			array( '<article>live</article>' ),
			$live_preview->filter_meta( null, self::POST_ID, 'story_body', false )
		);
	}

	public function test_meta_keys_the_plugin_does_not_own_are_left_alone(): void {
		$live_preview = $this->resolve_on_preview_request();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_id', true ) );
	}

	/**
	 * The whole-post form of get_post_meta() passes an empty key and expects
	 * every key back. Serving it here would mean calling get_post_meta() from
	 * inside its own filter.
	 */
	public function test_a_request_for_every_meta_key_is_left_alone(): void {
		$live_preview = $this->resolve_on_preview_request();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, '', false ) );
	}

	/**
	 * WordPress reads plenty of unrelated meta on a story request. None of it
	 * should cost a call to Shorthand.
	 */
	public function test_keys_the_plugin_does_not_own_cost_no_api_call(): void {
		$live_preview = $this->make_live_preview( $this->never_called_post_api() );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, '_thumbnail_id', true ) );
		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_id', true ) );
		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, '', false ) );
	}

	public function test_another_post_keeps_its_saved_content(): void {
		$live_preview = $this->resolve_on_preview_request();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID + 1, 'story_body', true ) );
	}

	public function test_an_ordinary_request_keeps_saved_content(): void {
		\tests_wp_set_is_preview( false );
		\tests_wp_set_singular( self::POST_TYPE );
		\tests_wp_set_queried_object_id( self::POST_ID );

		$live_preview = $this->make_live_preview( $this->never_called_post_api() );
		$live_preview->resolve();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_body', true ) );
	}

	public function test_a_singular_request_for_another_post_type_keeps_saved_content(): void {
		\tests_wp_set_is_preview( true );
		\tests_wp_set_singular( 'post' );
		\tests_wp_set_queried_object_id( self::POST_ID );

		$live_preview = $this->make_live_preview( $this->never_called_post_api() );
		$live_preview->resolve();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_body', true ) );
	}

	public function test_a_viewer_without_edit_rights_keeps_saved_content(): void {
		\tests_wp_set_current_user_can( false );

		$live_preview = $this->make_live_preview( $this->never_called_post_api() );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_body', true ) );
	}

	/**
	 * WordPress sets is_preview() from the `preview` query var alone, so an
	 * anonymous visitor can put any published story URL into this state.
	 */
	public function test_an_anonymous_visitor_appending_preview_true_keeps_saved_content(): void {
		\tests_wp_set_current_user_can( false );

		$live_preview = $this->make_live_preview( $this->never_called_post_api() );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_body', true ) );
		$this->assertSame( array(), \tests_wp_hook_callbacks( 'get_post_metadata' ) );
	}

	/**
	 * `nocache_headers()` and the DONOTCACHEPAGE constant are set together, so
	 * the call count stands in for both. Sending them outside the capability
	 * check would let anyone bypass the page cache with `?preview=true`.
	 */
	public function test_an_anonymous_visitor_does_not_get_the_page_cache_disabled(): void {
		\tests_wp_set_current_user_can( false );

		$live_preview = $this->make_live_preview( $this->never_called_post_api() );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$this->assertSame( 0, \tests_wp_nocache_calls() );
	}

	public function test_a_recognised_preview_disables_the_page_cache(): void {
		$this->resolve_on_preview_request();

		$this->assertSame( 1, \tests_wp_nocache_calls() );
	}

	public function test_a_disconnected_plugin_keeps_saved_content(): void {
		$live_preview = $this->make_live_preview( $this->never_called_post_api(), false );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$this->assertNull( $live_preview->filter_meta( null, self::POST_ID, 'story_body', true ) );
	}

	public function test_the_live_story_is_fetched_once_however_many_keys_are_read(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->once() )
			->method( 'get_preview_content' )
			->with( self::POST_ID )
			->willReturn( new StoryPreview( '<meta name="live">', '<article>live</article>', 9 ) );

		$live_preview = $this->make_live_preview( $post_api );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$live_preview->filter_meta( null, self::POST_ID, 'story_head', true );
		$live_preview->filter_meta( null, self::POST_ID, 'story_body', true );
		$live_preview->filter_meta( null, self::POST_ID, 'story_version', true );
		$live_preview->filter_meta( null, self::POST_ID, 'story_body', true );
	}

	public function test_a_failed_fetch_falls_back_to_saved_content_without_retrying(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->once() )->method( 'get_preview_content' )->willReturn( null );

		$live_preview = $this->make_live_preview( $post_api );
		$this->put_request_on_preview();
		$live_preview->resolve();

		$this->assertSame( 'saved', $live_preview->filter_meta( 'saved', self::POST_ID, 'story_body', true ) );
		$this->assertSame( 'saved', $live_preview->filter_meta( 'saved', self::POST_ID, 'story_head', true ) );
	}

	public function test_a_failed_fetch_tells_the_editor_that_the_story_is_stale(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_preview_content' )->willReturn( null );

		$live_preview = $this->make_live_preview( $post_api );
		$this->put_request_on_preview();
		$live_preview->resolve();

		ob_start();
		$live_preview->render_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'could not be refreshed', $output );
	}

	public function test_a_working_preview_shows_no_notice(): void {
		$live_preview = $this->resolve_on_preview_request();

		ob_start();
		$live_preview->render_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Core registers _wp_preview_meta_filter() on the same hook at 10, so the
	 * live values have to be applied after it.
	 */
	public function test_the_meta_filter_outranks_the_one_core_registers(): void {
		$live_preview = $this->resolve_on_preview_request();

		$registrations = \tests_wp_hook_callbacks( 'get_post_metadata' );

		$this->assertCount( 1, $registrations );
		$this->assertSame( array( $live_preview, 'filter_meta' ), $registrations[0]['callback'] );
		$this->assertSame( 11, $registrations[0]['priority'] );
		$this->assertSame( 4, $registrations[0]['accepted_args'] );
	}

	private function resolve_on_preview_request(): LivePreview {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_preview_content' )
			->willReturn( new StoryPreview( '<meta name="live">', '<article>live</article>', 9 ) );

		$live_preview = $this->make_live_preview( $post_api );
		$this->put_request_on_preview();
		$live_preview->resolve();

		return $live_preview;
	}

	private function put_request_on_preview(): void {
		\tests_wp_set_is_preview( true );
		\tests_wp_set_singular( self::POST_TYPE );
		\tests_wp_set_queried_object_id( self::POST_ID );
	}

	private function never_called_post_api(): PostAPI {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'get_preview_content' );

		return $post_api;
	}

	private function make_live_preview( PostAPI $post_api, bool $connected = true ): LivePreview {
		$auth_state_manager = $this->createMock( AuthStateManager::class );
		$auth_state_manager->method( 'is_connected' )->willReturn( $connected );

		$dependencies = $this->createMock( Dependencies::class );
		$dependencies->method( 'get_post_api' )->willReturn( $post_api );

		return new LivePreview( self::POST_TYPE, $auth_state_manager, $this->plugin_version(), $dependencies );
	}

	/**
	 * Resolves plugin paths against the source tree so partials really render.
	 */
	private function plugin_version(): Version {
		$version = $this->createMock( Version::class );
		$version->method( 'get_plugin_path' )->willReturnCallback(
			static function ( string $file = '' ): string {
				return dirname( __DIR__, 2 ) . '/src/' . $file;
			}
		);

		return $version;
	}
}
