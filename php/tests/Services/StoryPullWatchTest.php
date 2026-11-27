<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Cron;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Services\StoryCover;
use Shorthand\Services\StoryPullWatch;
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;

/**
 * Noticing, from a later request, a pull whose step died without a trace.
 */
final class StoryPullWatchTest extends WordPressTestCase {

	const PULL_PATH = 'vip://wp-content/uploads/shorthand/7/aBc123_12345';

	/**
	 * Uploads directory as an object store.
	 *
	 * @var \Shorthand\Tests\Support\FakeRemoteFileSystem
	 */
	private $file_system;

	/**
	 * Real PostAPI over the meta stubs.
	 *
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;

	/**
	 * Watch under test.
	 *
	 * @var \Shorthand\Services\StoryPullWatch
	 */
	private $watch;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'publish',
			)
		);
		tests_wp_set_post_meta( 7, 'story_id', 'aBc123' );
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );

		$this->file_system = new FakeRemoteFileSystem();
		$this->post_api    = $this->make_post_api();
		$this->watch       = new StoryPullWatch( $this->post_api, 'tse_story' );
	}

	public function test_a_post_without_a_pull_is_idle(): void {
		$this->assertSame( StoryPullWatch::IDLE, $this->watch->check( 7 ) );
		$this->assertSame( array(), tests_wp_updated_post_meta() );
	}

	/**
	 * A failed pull keeps its progress. Dropping it lets the sweep stop
	 * finding the post.
	 */
	public function test_a_failed_pull_is_idle_and_loses_its_progress(): void {
		$this->set_heartbeat( time() - 3600 );
		tests_wp_set_post_meta(
			7,
			'story_update_error',
			array(
				array(
					'code'    => 'status',
					'message' => 'Failed.',
					'data'    => 500,
				),
			)
		);

		$this->assertSame( StoryPullWatch::IDLE, $this->watch->check( 7 ) );
		$this->assertSame( '', get_post_meta( 7, 'story_update_state', true ) );
		$this->assertNotSame( '', get_post_meta( 7, 'story_update_error', true ) );
	}

	/**
	 * 1.0.11 wrote no heartbeat. Stamping one gives a pull in flight during
	 * the upgrade its full grace.
	 */
	public function test_progress_without_a_heartbeat_is_stamped_and_running(): void {
		tests_wp_set_post_meta(
			7,
			'story_update_state',
			array(
				'percent' => 40.0,
				'status'  => 'Saving story to WordPress',
			)
		);

		$this->assertSame( StoryPullWatch::RUNNING, $this->watch->check( 7 ) );

		$state = get_post_meta( 7, 'story_update_state', true );
		$this->assertSame( 40.0, $state['percent'] );
		$this->assertSame( 'Saving story to WordPress', $state['status'] );
		$this->assertGreaterThanOrEqual( time() - 1, $state['updated_at'] );
	}

	public function test_a_recent_heartbeat_is_running(): void {
		$this->set_heartbeat( time() - StoryPullWatch::STALE_AFTER + 10 );
		$this->store_task( 0 );

		$this->assertSame( StoryPullWatch::RUNNING, $this->watch->check( 7 ) );
		$this->assertSame( array(), tests_wp_scheduled_events() );
	}

	/**
	 * On a quiet site a queued step waits for traffic.
	 */
	public function test_a_stale_pull_with_its_step_still_queued_is_running(): void {
		$this->set_heartbeat( time() - 900 );
		$json = $this->store_task( 0 );
		wp_schedule_single_event( time() - 600, Cron::PULL_HOOK, array( $json ) );

		$this->assertSame( StoryPullWatch::RUNNING, $this->watch->check( 7 ) );
		$this->assertCount( 1, tests_wp_scheduled_events() );
		$this->assertSame( 0, $this->post_api->get_story_update_task( 7 )['attempts'] );
	}

	public function test_a_queued_step_does_not_hold_off_the_watch_forever(): void {
		$this->set_heartbeat( time() - StoryPullWatch::GIVE_UP_AFTER - 10 );
		$json = $this->store_task( 1 );
		wp_schedule_single_event( time() - StoryPullWatch::GIVE_UP_AFTER, Cron::PULL_HOOK, array( $json ) );

		$this->assertSame( StoryPullWatch::DEAD, $this->watch->check( 7 ) );
	}

	public function test_a_lost_step_is_resumed_once(): void {
		$this->set_heartbeat( time() - 600 );
		$json = $this->store_task( 0 );

		$this->assertSame( StoryPullWatch::RESUMED, $this->watch->check( 7 ) );

		$events = tests_wp_scheduled_events();
		$this->assertCount( 1, $events );
		$this->assertSame( Cron::PULL_HOOK, $events[0]['hook'] );
		$this->assertSame( array( $json ), $events[0]['args'], 'The stored JSON is scheduled byte for byte' );
		$this->assertSame( 1, $this->post_api->get_story_update_task( 7 )['attempts'] );
		$this->assertGreaterThanOrEqual( time() - 1, get_post_meta( 7, 'story_update_state', true )['updated_at'] );
		$this->assertSame( '', get_post_meta( 7, 'story_update_error', true ) );
	}

	/**
	 * The resumed step was lost too. The pull fails, the post returns to
	 * the status it held before the pull, and a late step exits on the new
	 * nonce.
	 */
	public function test_a_second_stall_of_the_same_step_is_dead(): void {
		$this->set_heartbeat( time() - 600 );
		$this->store_task( 0 );
		$this->file_system->put( self::PULL_PATH . '/file-0.part', 'first' );
		$this->file_system->put( self::PULL_PATH . '/file-1.part', 'partial chunk of the killed step' );

		$this->assertSame( StoryPullWatch::RESUMED, $this->watch->check( 7 ) );

		tests_wp_reset_cron_events();
		$this->set_heartbeat( time() - 600 );

		$this->assertSame( StoryPullWatch::DEAD, $this->watch->check( 7 ) );

		$error = $this->post_api->get_story_update_error( 7 );
		$this->assertSame( 'stalled', $error[0]['code'] );
		$this->assertContains( 'pretty', array_column( $error, 'code' ) );
		$this->assertNotSame( '12345', get_post_meta( 7, 'story_update_nonce', true ) );
		$this->assertSame(
			array(
				array(
					'ID'          => 7,
					'post_status' => 'draft',
				),
			),
			tests_wp_updated_posts()
		);
		$this->assertSame( array(), $this->file_system->objects() );
		$this->assertSame( '', get_post_meta( 7, 'story_update_task', true ) );
		$this->assertSame( StoryPullWatch::IDLE, $this->watch->check( 7 ) );
	}

	/**
	 * A save that finds a dead pull sets the post status itself, so the
	 * watch does not restore it with a nested `wp_update_post()`.
	 */
	public function test_a_dead_pull_found_by_a_save_keeps_the_status(): void {
		$this->set_heartbeat( time() - 600 );
		$this->store_task( 1 );

		$this->assertSame( StoryPullWatch::DEAD, $this->watch->check( 7, true ) );

		$this->assertSame( 'stalled', $this->post_api->get_story_update_error( 7 )[0]['code'] );
		$this->assertSame( array(), tests_wp_updated_posts() );
	}

	public function test_a_resume_that_cannot_be_scheduled_is_dead(): void {
		$this->set_heartbeat( time() - 600 );
		$this->store_task( 0 );
		tests_wp_fail_next_schedule();

		$this->assertSame( StoryPullWatch::DEAD, $this->watch->check( 7 ) );
		$this->assertSame( 'stalled', $this->post_api->get_story_update_error( 7 )[0]['code'] );
	}

	/**
	 * A pull started by 1.0.11 stored no step, so its prior status is
	 * unknown and the post keeps its status.
	 */
	public function test_a_stale_pull_without_a_stored_step_is_dead_and_keeps_its_status(): void {
		$this->set_heartbeat( time() - 600 );
		$this->file_system->put( self::PULL_PATH . '/file-0.part', 'first' );
		$this->file_system->put( self::PULL_PATH . '/file-1.part', 'partial chunk of the killed step' );
		tests_wp_set_post_meta(
			7,
			'story_pulls',
			array(
				'12345' => array(
					'path'  => self::PULL_PATH,
					'files' => 1,
				),
			)
		);

		$this->assertSame( StoryPullWatch::DEAD, $this->watch->check( 7 ) );

		$this->assertSame( 'stalled', $this->post_api->get_story_update_error( 7 )[0]['code'] );
		$this->assertSame( '', get_post_meta( 7, 'story_update_state', true ) );
		$this->assertNotSame( '12345', get_post_meta( 7, 'story_update_nonce', true ) );
		$this->assertSame( array(), tests_wp_updated_posts() );
		$this->assertSame( array(), $this->file_system->objects() );
	}

	public function test_the_sweep_checks_posts_with_a_pull_in_flight(): void {
		$this->set_heartbeat( time() - 600 );
		tests_wp_set_posts_query_result( array( 7 ) );
		wp_schedule_event( time(), Cron::WATCH_INTERVAL, Cron::WATCH_HOOK );

		$this->watch->sweep();

		$query = tests_wp_posts_queries()[0];
		$this->assertSame( 'tse_story', $query['post_type'] );
		$this->assertSame( 'story_update_state', $query['meta_key'] );
		$this->assertSame( StoryPullWatch::SWEEP_LIMIT, $query['numberposts'] );
		$this->assertSame( 'stalled', $this->post_api->get_story_update_error( 7 )[0]['code'] );
		$this->assertNotFalse( wp_next_scheduled( Cron::WATCH_HOOK ) );
	}

	public function test_the_sweep_unschedules_itself_when_no_pull_is_left(): void {
		wp_schedule_event( time(), Cron::WATCH_INTERVAL, Cron::WATCH_HOOK );

		$this->watch->sweep();

		$this->assertFalse( wp_next_scheduled( Cron::WATCH_HOOK ) );
	}

	private function set_heartbeat( int $updated_at ): void {
		tests_wp_set_post_meta(
			7,
			'story_update_state',
			array(
				'percent'    => 40.0,
				'status'     => 'Saving story to WordPress',
				'updated_at' => $updated_at,
			)
		);
	}

	/**
	 * Stores the step of the pull with nonce 12345, as Cron does.
	 *
	 * @param int $attempts How often the watch has resumed the step.
	 * @return string The stored JSON.
	 */
	private function store_task( int $attempts ): string {
		$task           = new StoryUpdateTask( 7, 'aBc123', '12345', 'draft', 'https://api.example.test/download/1', self::PULL_PATH );
		$task->file_url = 'https://cdn.example.test/bundle.zip';
		$task->size     = 3 * 1024 * 1024;
		$task->start    = 1024 * 1024;
		$task->end      = 2 * 1024 * 1024;
		$task->files    = 1;

		$json = wp_json_encode( $task );
		$this->post_api->set_story_update_task( 7, $json, $attempts );

		return $json;
	}

	private function make_post_api(): PostAPI {
		$auth = $this->createMock( AuthStateManager::class );
		$auth->method( 'is_connected' )->willReturn( true );

		return new PostAPI(
			$this->createMock( Shorthand::class ),
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth,
			$this->createMock( StoryContentTransformer::class ),
			$this->file_system,
			new StoryTextExtractor(),
			$this->createMock( StoryCover::class )
		);
	}
}
