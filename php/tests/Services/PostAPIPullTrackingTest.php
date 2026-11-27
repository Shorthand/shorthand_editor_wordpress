<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Services\StoryCover;
use Shorthand\Services\StoryPullSpeed;
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

/**
 * Tracking in-flight pull directories so their chunks can be removed.
 *
 * A superseded pull returns without cleaning up, and a pull directory cannot
 * be listed on a remote uploads directory. The `story_pulls` post meta key is
 * the only record of what it left behind.
 */
final class PostAPIPullTrackingTest extends WordPressTestCase {

	/** @var \Shorthand\Tests\Support\FakeRemoteFileSystem */
	private $file_system;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );
		tests_wp_set_post( 7, (object) array( 'ID' => 7 ) );
		tests_wp_set_post_meta( 7, 'story_id', 'aBc123' );

		$this->file_system = new FakeRemoteFileSystem();
	}

	public function test_beginning_a_pull_records_its_directory(): void {
		$task = $this->begin_pull();

		$this->assertInstanceOf( StoryUpdateTask::class, $task );
		$this->assertSame(
			array(
				$task->request_nonce => array(
					'path'  => 'vip://wp-content/uploads/shorthand/7/aBc123_' . $task->request_nonce,
					'files' => 0,
				),
			),
			get_post_meta( 7, 'story_pulls', true )
		);
	}

	public function test_beginning_a_pull_removes_the_chunks_of_a_superseded_one(): void {
		$stale = 'vip://wp-content/uploads/shorthand/7/aBc123_11111';

		$this->file_system->put( $stale . '/file-0.part', 'first' );
		$this->file_system->put( $stale . '/file-1.part', 'second' );

		tests_wp_set_post_meta(
			7,
			'story_pulls',
			array(
				'11111' => array(
					'path'  => $stale,
					'files' => 2,
				),
			)
		);

		$task = $this->begin_pull();

		$this->assertSame( array(), $this->file_system->objects() );
		$this->assertSame( 2, $this->file_system->deletes() );
		$this->assertSame( array( (int) $task->request_nonce ), array_keys( get_post_meta( 7, 'story_pulls', true ) ) );
	}

	public function test_a_pull_with_no_downloaded_chunks_is_swept_without_deleting_files(): void {
		tests_wp_set_post_meta(
			7,
			'story_pulls',
			array(
				'11111' => array(
					'path'  => 'vip://wp-content/uploads/shorthand/7/aBc123_11111',
					'files' => 0,
				),
			)
		);

		$this->begin_pull();

		$this->assertSame( 0, $this->file_system->deletes() );
	}

	private function begin_pull(): StoryUpdateTask {
		$auth = $this->createMock( AuthStateManager::class );
		$auth->method( 'is_connected' )->willReturn( true );

		$options = $this->createMock( Options::class );
		$options->method( 'get_api_url' )->willReturn( 'https://api.example.test' );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 202 ),
				'headers'  => array( 'Location' => 'https://api.example.test/download/1' ),
				'body'     => '',
			)
		);

		$task = $this->make_post_api( $shorthand, $options, $auth )->pull_story_begin( 7 );

		$this->assertInstanceOf( StoryUpdateTask::class, $task );

		return $task;
	}

	/**
	 * Meta writes unslash their value. The stored JSON must still match the
	 * event argument byte for byte, or `wp_next_scheduled()` never finds it.
	 */
	public function test_the_stored_task_keeps_its_json_exact(): void {
		$json = wp_json_encode( $this->task() );
		$this->assertStringContainsString( '\\/', $json );

		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, $json );

		$this->assertSame(
			array(
				'json'     => $json,
				'attempts' => 0,
			),
			$post_api->get_story_update_task( 7 )
		);
	}

	public function test_a_failed_pull_deletes_its_stored_task(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ) );

		$post_api->pull_story_failed( $this->task(), new WP_Error( 'status', 'Failed.' ) );

		$this->assertNull( $post_api->get_story_update_task( 7 ) );
	}

	/**
	 * A superseded step ending late must not delete the task of the pull
	 * that replaced it.
	 */
	public function test_cleanup_keeps_the_task_of_a_newer_pull(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$newer                = $this->task();
		$newer->request_nonce = '67890';

		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $newer ) );

		$post_api->pull_story_failed( $this->task(), new WP_Error( 'status', 'Failed.' ) );

		$this->assertNotNull( $post_api->get_story_update_task( 7 ) );
	}

	public function test_abandoning_a_pull_with_a_stored_task_fails_it_and_restores_its_status(): void {
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'publish',
			)
		);
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ) );

		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ) );

		$this->assertSame( 'stalled', $post_api->get_story_update_error( 7 )[0]['code'] );
		$this->assertSame(
			array(
				array(
					'ID'          => 7,
					'post_status' => 'draft',
				),
			),
			tests_wp_updated_posts()
		);
		$this->assertNotSame( '12345', get_post_meta( 7, 'story_update_nonce', true ) );
		$this->assertNull( $post_api->get_story_update_task( 7 ) );
	}

	/**
	 * A post trashed during its pull stays in the trash when the pull fails.
	 */
	public function test_a_failed_pull_leaves_a_trashed_post_in_the_trash(): void {
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'trash',
			)
		);
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );

		$this->make_post_api()->pull_story_failed( $this->task(), new WP_Error( 'status', 'Failed.' ) );

		$this->assertSame( array(), tests_wp_updated_posts() );
		$this->assertSame( 'status', $this->make_post_api()->get_story_update_error( 7 )[0]['code'] );
	}

	/**
	 * Putting a scheduled post back to `future` fires core's
	 * `_future_post_hook()`, which saves the whole `cron` option from the
	 * request's cached copy. A step can be a minute old by then.
	 */
	public function test_a_failed_pull_reads_the_cron_option_fresh_before_restoring_the_status(): void {
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'publish',
			)
		);
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$task               = $this->task();
		$task->prior_status = 'future';

		$cleared = null;
		tests_wp_set_update_post_observer(
			static function () use ( &$cleared ): void {
				$cleared = tests_wp_cron_cache_cleared();
			}
		);

		$this->make_post_api()->pull_story_failed( $task, new WP_Error( 'status', 'Failed.' ) );

		$this->assertSame( 'future', tests_wp_updated_posts()[0]['post_status'] );
		$this->assertTrue( $cleared );
	}

	public function test_abandoning_a_pull_of_a_trashed_post_leaves_it_in_the_trash(): void {
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'trash',
			)
		);
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ) );

		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ) );

		$this->assertSame( array(), tests_wp_updated_posts() );
	}

	public function test_abandoning_a_pull_during_a_save_leaves_the_status_to_the_save(): void {
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'publish',
			)
		);
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ) );

		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ), false );

		$this->assertSame( 'stalled', $post_api->get_story_update_error( 7 )[0]['code'] );
		$this->assertSame( array(), tests_wp_updated_posts() );
		$this->assertNull( $post_api->get_story_update_task( 7 ) );
	}

	public function test_abandoning_a_pull_without_a_stored_task_clears_it_and_keeps_the_status(): void {
		$stale = 'vip://wp-content/uploads/shorthand/7/aBc123_12345';
		$this->file_system->put( $stale . '/file-0.part', 'first' );
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'publish',
			)
		);
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		tests_wp_set_post_meta(
			7,
			'story_update_state',
			array(
				'percent' => 40.0,
				'status'  => 'Saving story to WordPress',
			)
		);
		tests_wp_set_post_meta(
			7,
			'story_pulls',
			array(
				'12345' => array(
					'path'  => $stale,
					'files' => 1,
				),
			)
		);

		$post_api = $this->make_post_api();
		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ) );

		$this->assertSame( 'stalled', $post_api->get_story_update_error( 7 )[0]['code'] );
		$this->assertNull( $post_api->get_story_update_progress( 7 ) );
		$this->assertSame( array(), tests_wp_updated_posts() );
		$this->assertNotSame( '12345', get_post_meta( 7, 'story_update_nonce', true ) );
		$this->assertSame( array(), $this->file_system->objects() );
		$this->assertSame( '', get_post_meta( 7, 'story_pulls', true ) );
	}

	public function test_waiting_for_shorthand_to_build_the_bundle_saves_a_heartbeat(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 202 ),
				'body'     => '',
			)
		);

		$post_api = $this->make_post_api( $shorthand );
		$result   = $post_api->pull_story_cron( $this->task() );

		$this->assertSame( 'retry', $result->get_error_code() );
		$progress = get_post_meta( 7, 'story_update_state', true );
		$this->assertSame( 'Waiting for Shorthand to prepare the story', $progress['status'] );
		$this->assertGreaterThanOrEqual( time() - 1, $progress['updated_at'] );
	}

	/**
	 * A step that a newer pull superseded while Shorthand built the bundle
	 * must not write its 0% over the newer pull's progress.
	 */
	public function test_a_superseded_step_waiting_for_shorthand_writes_no_progress(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 202 ),
				'body'     => '',
			)
		);

		$result = $this->make_post_api( $shorthand )->pull_story_cron(
			$this->task(),
			static function (): bool {
				return false;
			}
		);

		$this->assertNull( $result );
		$this->assertSame( '', get_post_meta( 7, 'story_update_state', true ) );
	}

	/**
	 * After "Commands out of sync", every later write in the request fails.
	 * The connection is probed, and reopened if needed, before the step's
	 * writes.
	 */
	public function test_a_chunk_download_checks_the_database_connection_before_writing(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );

		$GLOBALS['wpdb'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A recorder stands in for the database.
			/**
			 * `story_pulls` as each check found it.
			 *
			 * @var array<int, mixed>
			 */
			public $pulls_at_check = array();

			public function check_connection( bool $allow_bail = true ): bool {
				$this->pulls_at_check[] = get_post_meta( 7, 'story_pulls', true );
				return true;
			}
		};

		$file_system = $this->file_system;
		$shorthand   = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturnCallback(
			static function ( string $url, string $method, array $options ) use ( $file_system ): array {
				$file_system->put( $options['filename'], 'abcdef' );

				return array(
					'response' => array( 'code' => 206 ),
					'headers'  => array( 'Content-Range' => 'bytes 0-5/6' ),
					'body'     => '',
				);
			}
		);

		$task           = $this->task();
		$task->file_url = 'https://cdn.example.test/bundle.zip';
		$task->size     = 6;
		$task->end      = 6;

		$this->make_post_api( $shorthand )->pull_story_cron( $task );

		$this->assertSame( array( '', '' ), $GLOBALS['wpdb']->pulls_at_check, 'Checked at step start and after the download, both before the first write' );
		$this->assertSame( 1, get_post_meta( 7, 'story_pulls', true )['12345']['files'] );
	}

	public function test_a_step_that_matches_the_stored_task_is_current(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ) );

		$this->assertTrue( $post_api->is_current_step( $this->task() ) );
	}

	/**
	 * The event ran twice, or ran late, and the pull has moved on past it.
	 */
	public function test_a_step_behind_the_stored_task_is_out_of_date(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		$next        = $this->task();
		$next->start = 26214400;
		$post_api    = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $next ) );

		$this->assertFalse( $post_api->is_current_step( $this->task() ) );
	}

	public function test_a_step_of_a_superseded_pull_is_out_of_date(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '99999' );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ) );

		$this->assertFalse( $post_api->is_current_step( $this->task() ) );
	}

	/**
	 * 1.0.11 stored no task. Its events still run while the pull is in flight.
	 */
	public function test_a_step_with_no_stored_task_runs_while_the_pull_is_in_flight(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		tests_wp_set_post_meta( 7, 'story_update_state', $this->progress() );

		$this->assertTrue( $this->make_post_api()->is_current_step( $this->task() ) );
	}

	/**
	 * A finished pull keeps its nonce. A late copy of its last step would
	 * find the chunks gone, fail the pull and restore the old status.
	 */
	public function test_a_step_of_a_finished_pull_is_out_of_date(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );

		$this->assertFalse( $this->make_post_api()->is_current_step( $this->task() ) );
	}

	public function test_a_step_of_a_failed_pull_is_out_of_date(): void {
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		tests_wp_set_post_meta( 7, 'story_update_state', $this->progress() );
		$post_api = $this->make_post_api();
		$post_api->set_story_update_error( 7, new WP_Error( 'chunk', 'Short range.' ) );

		$this->assertFalse( $post_api->is_current_step( $this->task() ) );
	}

	/**
	 * The budget, plus one range fetched twice: each try waits up to the
	 * request timeout and the file close.
	 */
	public function test_the_step_limit_covers_a_range_fetched_twice_past_the_budget(): void {
		$this->assertSame( 15 + 2 * ( 30 + 60 ), $this->make_post_api()->get_step_limit() );

		tests_wp_set_filter_value( 'theshed_pull_step_budget', 0.5 );
		$this->assertSame( 181, $this->make_post_api()->get_step_limit() );
	}

	/**
	 * Progress of a pull in flight, as stored.
	 *
	 * @return array{percent: int, status: string}
	 */
	private function progress(): array {
		return array(
			'percent' => 40,
			'status'  => 'Saving story to WordPress',
		);
	}

	private function task(): StoryUpdateTask {
		return new StoryUpdateTask( 7, 'aBc123', '12345', 'draft', 'https://api.example.test/download/1', 'vip://wp-content/uploads/shorthand/7/aBc123_12345' );
	}

	private function make_post_api( ?Shorthand $shorthand = null, ?Options $options = null, ?AuthStateManager $auth = null ): PostAPI {
		return new PostAPI(
			$shorthand ?? $this->createMock( Shorthand::class ),
			$options ?? $this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth ?? $this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class ),
			$this->file_system,
			new StoryTextExtractor(),
			$this->createMock( StoryCover::class ),
			new StoryPullSpeed()
		);
	}
}
