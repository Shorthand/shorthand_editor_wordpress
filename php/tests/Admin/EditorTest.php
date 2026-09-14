<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\Actions\EditWithShorthand;
use Shorthand\Admin\Actions\PostPreview;
use Shorthand\Admin\Editor;
use Shorthand\Core\Loader;
use Shorthand\Core\Version;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Cron;
use Shorthand\Services\Options;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StorySyncProgress;
use Shorthand\Services\StoryTitleSync;
use Shorthand\Tests\WordPressTestCase;

final class EditorTest extends WordPressTestCase {

	/**
	 * Publishing is scheduled in `wp_insert_post_data()`. Saving a published
	 * post must not pull the story a second time.
	 *
	 * @dataProvider publishing_statuses
	 */
	public function test_saving_a_published_post_does_not_publish_the_story( string $post_status ): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'extract_story_content' );
		$post_api->expects( $this->never() )->method( 'set_post_story_version' );

		$editor = $this->make_editor( $post_api );
		$editor->save_shorthand_story( 7, (object) array( 'post_status' => $post_status ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function publishing_statuses(): array {
		return array(
			'published'  => array( 'publish' ),
			'scheduled'  => array( 'future' ),
		);
	}

	public function test_saving_an_unpublished_post_clears_the_recorded_story_version(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->once() )->method( 'set_post_story_version' )->with( 7, null );

		$editor = $this->make_editor( $post_api );
		$editor->save_shorthand_story( 7, (object) array( 'post_status' => 'draft' ) );
	}

	/**
	 * Overriding `preview_post_link` sent the Preview button at
	 * admin-post.php, which serves the story without the theme. Core's own URL
	 * is a story permalink, which the theme templates can dress.
	 */
	public function test_the_preview_link_core_builds_is_left_alone(): void {
		$loader = new Loader();
		$this->make_editor( $this->createMock( PostAPI::class ) )->init( $loader );
		$loader->register();

		$this->assertSame( array(), \tests_wp_hook_callbacks( 'preview_post_link' ) );
		$this->assertNotSame( array(), \tests_wp_hook_callbacks( 'post_row_actions' ) );
	}

	public function test_the_editor_no_longer_answers_the_preview_link_filter(): void {
		$this->assertFalse( method_exists( Editor::class, 'preview_post_link' ) );
	}

	/**
	 * @return array{0: string, 1: bool}[]
	 */
	public static function auth_states(): array {
		return array(
			'connected'    => array( 'connected', true ),
			'disconnected' => array( 'disconnected', false ),
		);
	}

	public function test_saving_a_published_post_while_disconnected_keeps_its_status_and_records_no_error(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'set_story_update_error' );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->never() )->method( 'schedule_pull_story' );

		$editor = $this->make_editor( $post_api, null, $cron, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_publishing_a_draft_with_story_content_while_disconnected_is_allowed_without_a_pull(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );
		\tests_wp_set_post_meta( 7, 'story_body', '<p>Story</p>' );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'set_story_update_error' );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->never() )->method( 'schedule_pull_story' );

		$editor = $this->make_editor( $post_api, null, $cron, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_publishing_a_draft_without_story_content_while_disconnected_is_refused(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->once() )->method( 'set_story_update_error' )->with(
			7,
			$this->callback(
				static function ( $error ): bool {
					return 'auth' === $error->get_error_code() && false !== strpos( $error->get_error_message(), 'fetch the story content' );
				}
			)
		);

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->never() )->method( 'schedule_pull_story' );

		$editor = $this->make_editor( $post_api, null, $cron, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_a_first_time_publish_while_disconnected_returns_to_draft(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'auto-draft' ) );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_unpublishing_needs_no_connection(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'set_story_update_error' );

		$editor = $this->make_editor( $post_api, null, null, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'draft' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_publishing_a_draft_while_connected_pulls_the_story(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->with( 7 )->willReturn( true );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, $cron, $this->auth( true ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_saving_a_published_post_at_the_same_content_version_schedules_no_pull(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( 12 );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->once() )->method( 'get_story_version' )->with( 'abc123' )->willReturn( 12 );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->never() )->method( 'schedule_pull_story' );

		$editor = $this->make_editor( $post_api, $shorthand, $cron, $this->auth( true ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_saving_a_published_post_at_a_new_content_version_pulls_the_story(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( 12 );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_version' )->willReturn( 13 );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->with( 7 )->willReturn( true );

		$editor = $this->make_editor( $post_api, $shorthand, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_saving_a_published_post_whose_last_pull_failed_pulls_again(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_story_update_error' )->willReturn( array( array( 'code' => 'story', 'message' => 'Failed.' ) ) );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->never() )->method( 'get_story_version' );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->willReturn( true );

		$editor = $this->make_editor( $post_api, $shorthand, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_saving_a_published_post_without_a_bundle_pulls_the_story(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( null );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->willReturn( true );

		$editor = $this->make_editor( $post_api, null, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_saving_a_published_post_during_a_pull_does_not_restart_it(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_story_update_progress' )->willReturn( new StorySyncProgress( 40, 'Downloading' ) );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->never() )->method( 'schedule_pull_story' );

		$editor = $this->make_editor( $post_api, null, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_a_failed_version_check_falls_back_to_a_pull(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( 12 );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_version' )->willReturn( new \WP_Error( 'status', 'Received HTTP status 500.' ) );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->willReturn( true );

		$editor = $this->make_editor( $post_api, $shorthand, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_a_pull_that_cannot_be_scheduled_restores_the_prior_status(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->exactly( 2 ) )->method( 'set_story_update_error' );

		$cron = $this->createMock( Cron::class );
		$cron->method( 'schedule_pull_story' )->willReturn( false );

		$editor = $this->make_editor( $post_api, null, $cron, $this->auth( true ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	/**
	 * @dataProvider auth_states
	 */
	public function test_a_title_change_is_handed_to_the_title_sync_in_every_auth_state( string $state, bool $connected ): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );
		\tests_wp_set_post_field( 7, 'post_title', 'Old title' );

		$title_sync = $this->createMock( StoryTitleSync::class );
		$title_sync->expects( $this->once() )->method( 'push' )->with( 7, 'abc123', "New 'title'" );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, $this->auth( $connected ), $title_sync );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'draft', "New \\'title\\'" ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( "New \\'title\\'", $data['post_title'], 'The WordPress title saves as given' );
	}

	public function test_an_unchanged_title_with_a_held_change_is_pushed_again(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );
		\tests_wp_set_post_field( 7, 'post_title', 'Same title' );

		$title_sync = $this->createMock( StoryTitleSync::class );
		$title_sync->method( 'get_pending' )->willReturn( 'Same title' );
		$title_sync->expects( $this->once() )->method( 'push' )->with( 7, 'abc123', 'Same title' );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, $this->auth( true ), $title_sync );
		$editor->wp_insert_post_data( $this->post_data( 'draft', 'Same title' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_an_unchanged_title_is_not_pushed(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );
		\tests_wp_set_post_field( 7, 'post_title', 'Same title' );

		$title_sync = $this->createMock( StoryTitleSync::class );
		$title_sync->expects( $this->never() )->method( 'push' );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, $this->auth( true ), $title_sync );
		$editor->wp_insert_post_data( $this->post_data( 'draft', 'Same title' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_the_story_state_carries_a_held_title(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( 3 );

		$title_sync = $this->createMock( StoryTitleSync::class );
		$title_sync->method( 'get_pending' )->willReturn( 'Held title' );

		$editor = $this->make_editor( $post_api, null, null, null, $title_sync );

		$this->assertSame( 'Held title', $editor->get_post_story_state( 7 )['pendingTitle'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function post_data( string $status, string $title = 'Title' ): array {
		return array(
			'post_type'   => 'tse_story',
			'post_status' => $status,
			'post_title'  => $title,
		);
	}

	private function auth( bool $connected ): AuthStateManager {
		$auth = $this->createMock( AuthStateManager::class );
		$auth->method( 'is_connected' )->willReturn( $connected );
		return $auth;
	}

	private function make_editor(
		PostAPI $post_api,
		?Shorthand $shorthand = null,
		?Cron $cron = null,
		?AuthStateManager $auth = null,
		?StoryTitleSync $title_sync = null
	): Editor {
		return new Editor(
			$this->createMock( Options::class ),
			$shorthand ?? $this->createMock( Shorthand::class ),
			$cron ?? $this->createMock( Cron::class ),
			new Version(),
			$post_api,
			$this->createMock( PostPreview::class ),
			$this->createMock( EditWithShorthand::class ),
			'tse_story',
			$auth ?? $this->createMock( AuthStateManager::class ),
			$title_sync ?? $this->createMock( StoryTitleSync::class )
		);
	}
}
