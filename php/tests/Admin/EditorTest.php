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
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryCover;
use Shorthand\Services\StorySyncProgress;
use Shorthand\Services\StoryTitleSync;
use Shorthand\Tests\WordPressTestCase;
use Tests_WP_Die_Exception;
use WP_Error;
use WP_Post;

final class EditorTest extends WordPressTestCase {

	/**
	 * Publishing is scheduled in `wp_insert_post_data()`. Saving a published
	 * post must not pull the story a second time.
	 *
	 * @dataProvider publishing_statuses
	 */
	public function test_saving_a_published_post_does_not_publish_the_story( string $post_status ): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'publish_story_bundle' );
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

	public function test_the_featured_image_box_of_other_post_types_is_left_alone(): void {
		\tests_wp_set_post( 7, new WP_Post( array( 'ID' => 7, 'post_type' => 'post' ) ) );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ) );

		$this->assertSame( '<p>box</p>', $editor->admin_post_thumbnail_html( '<p>box</p>', 7, null ) );
	}

	public function test_a_story_without_a_story_id_gets_no_cover_panel(): void {
		\tests_wp_set_post( 7, new WP_Post( array( 'ID' => 7, 'post_type' => 'tse_story' ) ) );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ) );

		$this->assertSame( '<p>box</p>', $editor->admin_post_thumbnail_html( '<p>box</p>', 7, null ) );
	}

	/**
	 * The panel renders from the cover the last publish recorded; the client
	 * refreshes it afterwards. Core's markup moves under the second tab.
	 */
	public function test_the_cover_panel_wraps_the_featured_image_box(): void {
		$this->set_story_post( 7 );
		\tests_wp_set_post_meta( 7, 'story_cover', $this->cover() );
		\tests_wp_set_post_meta( 7, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( 7, '_thumbnail_id', 300 );

		$story_cover = new StoryCover( $this->createMock( Shorthand::class ) );
		$html        = $this->make_editor( $this->createMock( PostAPI::class ), null, $story_cover )
			->admin_post_thumbnail_html( '<p>box</p>', 7, 300 );

		$this->assertStringStartsWith( '<div id="theshed-cover-panel"', $html );
		$this->assertStringContainsString( 'data-view="story"', $html );
		$this->assertStringContainsString( 'data-state="current"', $html );
		$this->assertStringContainsString( '<p><img class="theshed-cover-panel__image" src="" alt="" hidden></p>', $html );
		$this->assertStringContainsString( $story_cover->describe( StoryCover::STATE_CURRENT ), $html );
		$this->assertMatchesRegularExpression( '/theshed-cover-panel__import" hidden>/', $html );
		$this->assertMatchesRegularExpression( '/data-theshed-view="featured" hidden>\s*<p>box<\/p>/', $html );
	}

	/**
	 * Core passes the form's unsaved choice, which may differ from the saved one.
	 */
	public function test_the_cover_panel_judges_the_thumbnail_core_passes(): void {
		$this->set_story_post( 7 );
		\tests_wp_set_post_meta( 7, 'story_cover', $this->cover() );
		\tests_wp_set_post_meta( 7, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( 7, '_thumbnail_id', 300 );

		$html = $this->make_editor( $this->createMock( PostAPI::class ) )->admin_post_thumbnail_html( '', 7, null );

		$this->assertStringContainsString( 'data-state="overridden"', $html );
		$this->assertMatchesRegularExpression( '/theshed-cover-panel__import" >/', $html );
	}

	public function test_the_cover_panel_waits_for_the_client_when_no_cover_is_recorded(): void {
		$this->set_story_post( 7 );

		$html = $this->make_editor( $this->createMock( PostAPI::class ) )->admin_post_thumbnail_html( '', 7, null );

		$this->assertStringContainsString( 'data-state="unknown"', $html );
		$this->assertStringContainsString( 'hidden', $html );
	}

	public function test_the_cover_ajax_refuses_a_bad_nonce_before_calling_shorthand(): void {
		$_GET['post'] = '7';
		\tests_wp_set_verify_nonce( false );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->expects( $this->never() )->method( 'fetch' );

		$this->expectException( Tests_WP_Die_Exception::class );
		$this->make_editor( $this->createMock( PostAPI::class ), null, $story_cover )->ajax_get_story_cover();
	}

	public function test_the_cover_ajax_refuses_a_user_who_cannot_pull_the_story(): void {
		$_GET['post'] = '7';

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->with( 7 )->willReturn( false );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->expects( $this->never() )->method( 'fetch' );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_get_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$response = \tests_wp_json_responses()[0];
			$this->assertFalse( $response['success'] );
			$this->assertSame( 403, $response['status'] );
		}
	}

	public function test_the_cover_ajax_returns_the_cover_and_its_state_without_writing_meta(): void {
		$_GET['post'] = '7';
		$this->set_story_post( 7 );
		\tests_wp_set_post_meta( 7, '_thumbnail_id', 9 );

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->willReturn( true );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->with( 'aBc123' )->willReturn(
			array(
				'id'   => 'aBc123',
				'meta' => array( 'cover' => $this->cover() ),
			)
		);
		$story_cover = new StoryCover( $shorthand );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_get_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$response = \tests_wp_json_responses()[0];
			$this->assertTrue( $response['success'] );
			$this->assertSame( 'c1', $response['data']['cover']['id'] );
			$this->assertSame( 'https://cdn.example.test/c1.jpg?sig=abc', $response['data']['cover']['url'] );
			$this->assertSame( StoryCover::STATE_MANUAL, $response['data']['state'] );
			$this->assertSame( $story_cover->describe( StoryCover::STATE_MANUAL ), $response['data']['message'] );
			$this->assertTrue( $response['data']['importable'] );
			$this->assertSame( 9, $response['data']['thumbnail'] );
		}

		$this->assertSame( array(), \tests_wp_updated_post_meta() );
	}

	/**
	 * `meta.cover` is null for a story without a cover.
	 */
	public function test_the_cover_ajax_reports_a_story_without_a_cover(): void {
		$_GET['post'] = '7';
		$this->set_story_post( 7 );

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->willReturn( true );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->with( 'aBc123' )->willReturn(
			array(
				'id'   => 'aBc123',
				'meta' => array( 'cover' => null ),
			)
		);
		$story_cover = new StoryCover( $shorthand );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_get_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$response = \tests_wp_json_responses()[0];
			$this->assertTrue( $response['success'] );
			$this->assertNull( $response['data']['cover'] );
			$this->assertSame( StoryCover::STATE_NONE, $response['data']['state'] );
			$this->assertSame( $story_cover->describe( StoryCover::STATE_NONE ), $response['data']['message'] );
			$this->assertFalse( $response['data']['importable'] );
		}

		$this->assertSame( array( 'cover' => null ), \tests_wp_get_transient( 'shorthand_story_cover_aBc123' ) );
	}

	public function test_the_cover_ajax_judges_the_thumbnail_the_form_sends(): void {
		$_GET['post']      = '7';
		$_GET['thumbnail'] = '-1';
		$this->set_story_post( 7 );
		\tests_wp_set_post_meta( 7, '_thumbnail_id', 9 );

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->willReturn( true );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->method( 'fetch' )->willReturn( $this->cover() );
		$story_cover->expects( $this->once() )->method( 'state' )->with( 7, $this->cover(), 0 )->willReturn( StoryCover::STATE_PENDING );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_get_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$this->assertSame( StoryCover::STATE_PENDING, \tests_wp_json_responses()[0]['data']['state'] );
		}
	}

	public function test_the_import_ajax_refuses_a_bad_nonce_before_importing(): void {
		$_POST['post'] = '7';
		\tests_wp_set_verify_nonce( false );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->expects( $this->never() )->method( 'sync' );

		$this->expectException( Tests_WP_Die_Exception::class );
		$this->make_editor( $this->createMock( PostAPI::class ), null, $story_cover )->ajax_import_story_cover();
	}

	public function test_the_import_ajax_refuses_a_user_who_cannot_pull_the_story(): void {
		$_POST['post'] = '7';

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->with( 7 )->willReturn( false );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->expects( $this->never() )->method( 'sync' );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_import_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$this->assertSame( 403, \tests_wp_json_responses()[0]['status'] );
		}
	}

	/**
	 * The button replaces whatever is featured, and tells the client the new
	 * attachment so the form can follow.
	 */
	public function test_the_import_ajax_replaces_the_featured_image_now(): void {
		$_POST['post'] = '7';
		$this->set_story_post( 7 );
		\tests_wp_set_post_meta( 7, '_thumbnail_id', 501 );

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->willReturn( true );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->expects( $this->once() )->method( 'sync' )->with( 7, 'aBc123', true )->willReturn( StoryCover::OUTCOME_IMPORTED );
		$story_cover->method( 'fetch' )->with( 'aBc123', true )->willReturn( $this->cover() );
		$story_cover->method( 'state' )->willReturn( StoryCover::STATE_CURRENT );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_import_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$response = \tests_wp_json_responses()[0];
			$this->assertTrue( $response['success'] );
			$this->assertSame( StoryCover::STATE_CURRENT, $response['data']['state'] );
			$this->assertFalse( $response['data']['importable'] );
			$this->assertSame( 501, $response['data']['thumbnail'] );
		}
	}

	/**
	 * @dataProvider import_failures
	 */
	public function test_the_import_ajax_reports_why_nothing_was_imported( string $outcome, int $status ): void {
		$_POST['post'] = '7';
		$this->set_story_post( 7 );

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->willReturn( true );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->method( 'sync' )->willReturn( $outcome );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_import_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$response = \tests_wp_json_responses()[0];
			$this->assertFalse( $response['success'] );
			$this->assertSame( $status, $response['status'] );
		}
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function import_failures(): array {
		return array(
			'import failed' => array( StoryCover::OUTCOME_FAILED, 502 ),
			'no cover'      => array( StoryCover::STATE_NONE, 404 ),
		);
	}

	public function test_the_cover_ajax_reports_a_failed_settings_call(): void {
		$_GET['post'] = '7';
		$this->set_story_post( 7 );

		$permissions = $this->createMock( Permissions::class );
		$permissions->method( 'can_pull_story' )->willReturn( true );

		$story_cover = $this->createMock( StoryCover::class );
		$story_cover->method( 'fetch' )->willReturn( new WP_Error( 'http', 'timeout' ) );

		try {
			$this->make_editor( $this->createMock( PostAPI::class ), $permissions, $story_cover )->ajax_get_story_cover();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( Tests_WP_Die_Exception $e ) {
			$response = \tests_wp_json_responses()[0];
			$this->assertFalse( $response['success'] );
			$this->assertSame( 502, $response['status'] );
		}
	}

	public function test_the_cover_hooks_are_registered(): void {
		$loader = new Loader();
		$this->make_editor( $this->createMock( PostAPI::class ) )->init( $loader );
		$loader->register();

		$this->assertNotSame( array(), \tests_wp_hook_callbacks( 'admin_post_thumbnail_html' ) );
		$this->assertNotSame( array(), \tests_wp_hook_callbacks( 'wp_ajax_shorthand_get_story_cover' ) );
		$this->assertNotSame( array(), \tests_wp_hook_callbacks( 'wp_ajax_shorthand_import_story_cover' ) );
	}

	protected function tearDown(): void {
		unset( $_GET['post'], $_GET['thumbnail'], $_POST['post'] );
		parent::tearDown();
	}

	private function set_story_post( int $post_id ): void {
		\tests_wp_set_post( $post_id, new WP_Post( array( 'ID' => $post_id, 'post_type' => 'tse_story' ) ) );
		\tests_wp_set_post_meta( $post_id, 'story_id', 'aBc123' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cover(): array {
		return array(
			'id'        => 'c1',
			'signedUrl' => 'https://cdn.example.test/c1.jpg?sig=abc',
			'mime'   => 'image/jpeg',
			'name'   => 'cover.jpg',
			'size'   => 1200,
			'width'  => 800,
			'height' => 600,
		);
	}

	/**
	 * A warning describes a finished publish, so the editor shows it only when
	 * there is no error and no publish in progress to report instead.
	 */
	public function test_the_story_state_carries_the_publishing_warning(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_story_update_warning' )->willReturn( $this->collision_warning() );

		$state = $this->make_editor( $post_api )->get_post_story_state( 7 );

		$this->assertSame( $this->collision_warning(), $state['warnings']['publishing'] );
	}

	public function test_a_publishing_error_hides_the_warning(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_story_update_error' )->willReturn(
			array(
				array(
					'code'    => 'story',
					'message' => 'Story being published',
					'data'    => 'aBc123',
				),
			)
		);
		$post_api->method( 'get_story_update_warning' )->willReturn( $this->collision_warning() );

		$state = $this->make_editor( $post_api )->get_post_story_state( 7 );

		$this->assertNull( $state['warnings']['publishing'] );
	}

	public function test_a_publish_in_progress_hides_the_warning(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_story_update_progress' )->willReturn( new StorySyncProgress( 40, 'Saving story to WordPress' ) );
		$post_api->method( 'get_story_update_warning' )->willReturn( $this->collision_warning() );

		$state = $this->make_editor( $post_api )->get_post_story_state( 7 );

		$this->assertNull( $state['warnings']['publishing'] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function collision_warning(): array {
		return array(
			array(
				'message' => 'assets/AbC/x.jpg and assets/abc/x.jpg',
				'data'    => array( array( 'assets/AbC/x.jpg', 'assets/abc/x.jpg' ) ),
				'code'    => 'collision',
			),
		);
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

		$editor = $this->make_editor( $post_api, null, null, null, $cron, $this->auth( false ) );
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

		$editor = $this->make_editor( $post_api, null, null, null, $cron, $this->auth( false ) );
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

		$editor = $this->make_editor( $post_api, null, null, null, $cron, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_a_first_time_publish_while_disconnected_returns_to_draft(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'auto-draft' ) );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, null, null, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_unpublishing_needs_no_connection(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'set_story_update_error' );

		$editor = $this->make_editor( $post_api, null, null, null, null, $this->auth( false ) );
		$data   = $editor->wp_insert_post_data( $this->post_data( 'draft' ), array( 'ID' => 7 ), array(), true );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_publishing_a_draft_while_connected_pulls_the_story(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->with( 7 )->willReturn( true );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, null, $cron, $this->auth( true ) );
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

		$editor = $this->make_editor( $post_api, null, null, $shorthand, $cron, $this->auth( true ) );
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

		$editor = $this->make_editor( $post_api, null, null, $shorthand, $cron, $this->auth( true ) );
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

		$editor = $this->make_editor( $post_api, null, null, $shorthand, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_saving_a_published_post_without_a_bundle_pulls_the_story(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( null );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->once() )->method( 'schedule_pull_story' )->willReturn( true );

		$editor = $this->make_editor( $post_api, null, null, null, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_saving_a_published_post_during_a_pull_does_not_restart_it(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'publish' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_story_update_progress' )->willReturn( new StorySyncProgress( 40, 'Downloading' ) );

		$cron = $this->createMock( Cron::class );
		$cron->expects( $this->never() )->method( 'schedule_pull_story' );

		$editor = $this->make_editor( $post_api, null, null, null, $cron, $this->auth( true ) );
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

		$editor = $this->make_editor( $post_api, null, null, $shorthand, $cron, $this->auth( true ) );
		$editor->wp_insert_post_data( $this->post_data( 'publish' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_a_pull_that_cannot_be_scheduled_restores_the_prior_status(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->exactly( 2 ) )->method( 'set_story_update_error' );

		$cron = $this->createMock( Cron::class );
		$cron->method( 'schedule_pull_story' )->willReturn( false );

		$editor = $this->make_editor( $post_api, null, null, null, $cron, $this->auth( true ) );
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

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, null, null, $this->auth( $connected ), $title_sync );
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

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, null, null, $this->auth( true ), $title_sync );
		$editor->wp_insert_post_data( $this->post_data( 'draft', 'Same title' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_an_unchanged_title_is_not_pushed(): void {
		\tests_wp_set_post( 7, (object) array( 'post_status' => 'draft' ) );
		\tests_wp_set_post_meta( 7, 'story_id', 'abc123' );
		\tests_wp_set_post_field( 7, 'post_title', 'Same title' );

		$title_sync = $this->createMock( StoryTitleSync::class );
		$title_sync->expects( $this->never() )->method( 'push' );

		$editor = $this->make_editor( $this->createMock( PostAPI::class ), null, null, null, null, $this->auth( true ), $title_sync );
		$editor->wp_insert_post_data( $this->post_data( 'draft', 'Same title' ), array( 'ID' => 7 ), array(), true );
	}

	public function test_the_story_state_carries_a_held_title(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_post_story_version' )->willReturn( 3 );

		$title_sync = $this->createMock( StoryTitleSync::class );
		$title_sync->method( 'get_pending' )->willReturn( 'Held title' );

		$editor = $this->make_editor( $post_api, null, null, null, null, null, $title_sync );

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
		?Permissions $permissions = null,
		?StoryCover $story_cover = null,
		?Shorthand $shorthand = null,
		?Cron $cron = null,
		?AuthStateManager $auth = null,
		?StoryTitleSync $title_sync = null
	): Editor {
		return new Editor(
			$this->createMock( Options::class ),
			$shorthand ?? $this->createMock( Shorthand::class ),
			$cron ?? $this->createMock( Cron::class ),
			$this->plugin_version(),
			$post_api,
			$this->createMock( PostPreview::class ),
			$this->createMock( EditWithShorthand::class ),
			'tse_story',
			$auth ?? $this->createMock( AuthStateManager::class ),
			$permissions ?? $this->createMock( Permissions::class ),
			$story_cover ?? new StoryCover( $this->createMock( Shorthand::class ) ),
			$title_sync ?? $this->createMock( StoryTitleSync::class )
		);
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
