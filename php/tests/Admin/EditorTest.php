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

	private function make_editor( PostAPI $post_api, ?Permissions $permissions = null, ?StoryCover $story_cover = null ): Editor {
		return new Editor(
			$this->createMock( Options::class ),
			$this->createMock( Shorthand::class ),
			$this->createMock( Cron::class ),
			$this->plugin_version(),
			$post_api,
			$this->createMock( PostPreview::class ),
			$this->createMock( EditWithShorthand::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class ),
			$permissions ?? $this->createMock( Permissions::class ),
			$story_cover ?? new StoryCover( $this->createMock( Shorthand::class ) )
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
