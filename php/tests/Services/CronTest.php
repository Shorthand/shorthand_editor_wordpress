<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Plugin\Dependencies;
use Shorthand\Services\Cron;
use Shorthand\Services\PostAPI;
use Shorthand\Services\StoryPullLock;
use Shorthand\Services\StoryPullWatch;
use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

/**
 * Scheduling the steps of a story pull, and the watch over them.
 */
final class CronTest extends WordPressTestCase {

	/**
	 * Step lock option of post 7.
	 */
	private const LOCK = 'shorthand_pull_lock_7';

	/**
	 * The watch looks for the stored step when its event is lost, so the
	 * step must be stored even when scheduling fails.
	 */
	public function test_the_first_step_is_stored_before_it_is_scheduled(): void {
		$task     = $this->task();
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'pull_story_begin' )->willReturn( $task );
		$post_api->expects( $this->once() )->method( 'set_story_update_task' )->willReturnCallback(
			function ( int $post_id, string $json ) use ( $task ): void {
				$this->assertSame( 7, $post_id );
				$this->assertSame( wp_json_encode( $task ), $json );
				$this->assertSame( array(), tests_wp_scheduled_events(), 'Stored before scheduling' );
			}
		);

		$result = $this->make_cron( $post_api )->schedule_pull_story( 7 );

		$this->assertTrue( $result );
		$this->assertSame( array( wp_json_encode( $task ) ), tests_wp_scheduled_events()[0]['args'] );
	}

	public function test_scheduling_a_pull_schedules_the_watch_once(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'pull_story_begin' )->willReturn( $this->task() );

		$cron = $this->make_cron( $post_api );
		$cron->schedule_pull_story( 7 );
		$cron->schedule_pull_story( 7 );

		$watches = array_values(
			array_filter(
				tests_wp_scheduled_events(),
				static function ( array $event ): bool {
					return Cron::WATCH_HOOK === $event['hook'];
				}
			)
		);

		$this->assertCount( 1, $watches );
		$this->assertSame( Cron::WATCH_INTERVAL, $watches[0]['recurrence'] );
	}

	public function test_a_first_step_that_cannot_be_scheduled_schedules_no_watch(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'pull_story_begin' )->willReturn( $this->task() );
		$post_api->expects( $this->once() )->method( 'set_story_update_task' );
		tests_wp_fail_next_schedule();

		$this->assertFalse( $this->make_cron( $post_api )->schedule_pull_story( 7 ) );
		$this->assertFalse( wp_next_scheduled( Cron::WATCH_HOOK ) );
	}

	public function test_the_next_step_is_stored_before_it_is_scheduled(): void {
		$task     = $this->task();
		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->once() )->method( 'set_story_update_task' )->willReturnCallback(
			function ( int $post_id, string $json ): void {
				$this->assertSame( 7, $post_id );
				$this->assertSame( 7, json_decode( $json, true )['post_id'] );
				$this->assertSame( array(), tests_wp_scheduled_events(), 'Stored before scheduling' );
			}
		);
		$post_api->expects( $this->never() )->method( 'pull_story_failed' );

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $task ) );

		$events = tests_wp_scheduled_events();
		$this->assertCount( 1, $events );
		$this->assertSame( Cron::PULL_HOOK, $events[0]['hook'] );
		$this->assertGreaterThanOrEqual( time() + 4, $events[0]['timestamp'] );
	}

	/**
	 * Another run saved an identical cron array, so `update_option()`
	 * reported a failure although the event is there.
	 */
	public function test_a_failed_reschedule_whose_event_is_found_on_reread_succeeds(): void {
		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->never() )->method( 'pull_story_failed' );
		tests_wp_fail_next_schedule( true );

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertContains(
			array(
				'key'   => 'alloptions',
				'group' => 'options',
			),
			tests_wp_cache_deletes()
		);
		$this->assertCount( 1, tests_wp_scheduled_events() );
	}

	public function test_a_failed_reschedule_is_retried_once(): void {
		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->never() )->method( 'pull_story_failed' );
		tests_wp_fail_next_schedule();

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertCount( 1, tests_wp_scheduled_events() );
	}

	public function test_a_reschedule_that_fails_twice_fails_the_pull(): void {
		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->once() )->method( 'pull_story_failed' )->with(
			$this->isInstanceOf( StoryUpdateTask::class ),
			$this->callback(
				static function ( WP_Error $error ): bool {
					return 'could_not_set' === $error->get_error_code();
				}
			)
		);
		tests_wp_fail_next_schedule();
		tests_wp_fail_next_schedule();

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertSame( array(), tests_wp_scheduled_events() );
	}

	public function test_a_step_runs_holding_the_lock_and_releases_it(): void {
		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->once() )->method( 'get_step_limit' )->willReturn( 200 );
		$post_api->expects( $this->once() )->method( 'set_story_update_task' )->willReturnCallback(
			function (): void {
				$this->assertGreaterThanOrEqual( time() + 199, (int) get_option( self::LOCK ), 'Held while the next step is stored' );
			}
		);

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertFalse( get_option( self::LOCK ) );
		$this->assertCount( 1, tests_wp_scheduled_events() );
	}

	public function test_the_completion_step_holds_the_lock_for_15_minutes(): void {
		$task        = $this->task();
		$task->size  = 100;
		$task->start = 100;

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'get_step_limit' );
		$post_api->method( 'is_current_step' )->willReturn( true );
		$post_api->expects( $this->once() )->method( 'pull_story_cron' )->willReturnCallback(
			function (): ?WP_Error {
				$this->assertGreaterThanOrEqual( time() + 899, (int) get_option( self::LOCK ) );
				return null;
			}
		);

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $task ) );

		$this->assertFalse( get_option( self::LOCK ) );
	}

	/**
	 * After "Commands out of sync", a lock query would fail and the step
	 * would look locked out.
	 */
	public function test_the_connection_is_checked_before_the_lock_query(): void {
		$this->make_cron( $this->retrying_post_api() )->pull_story_cron( wp_json_encode( $this->task() ) );

		$queries = tests_wp_db_queries();
		$this->assertSame( 'check_connection', $queries[0] );
		$this->assertStringStartsWith( 'INSERT IGNORE', $queries[1] );
	}

	/**
	 * The holder may have written the pull's meta since this request read it.
	 */
	public function test_the_post_meta_cache_is_cleared_once_the_lock_is_taken(): void {
		$this->make_cron( $this->retrying_post_api() )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertContains(
			array(
				'key'   => '7',
				'group' => 'post_meta',
			),
			tests_wp_cache_deletes()
		);
	}

	public function test_a_held_lock_makes_the_step_try_again_in_30_seconds(): void {
		$json = wp_json_encode( $this->task() );
		$held = (string) ( time() + 100 );
		update_option( self::LOCK, $held );

		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->never() )->method( 'pull_story_cron' );
		$post_api->expects( $this->never() )->method( 'pull_story_failed' );
		$post_api->expects( $this->never() )->method( 'set_story_update_task' );

		$this->make_cron( $post_api )->pull_story_cron( $json );

		$events = tests_wp_scheduled_events();
		$this->assertCount( 1, $events );
		$this->assertSame( array( $json ), $events[0]['args'], 'The same event, so the stored task still finds it' );
		$this->assertGreaterThanOrEqual( time() + Cron::LOCKED_RETRY_DELAY, $events[0]['timestamp'] );
		$this->assertSame( $held, get_option( self::LOCK ), 'The holder keeps its lock' );
	}

	public function test_a_held_lock_drops_a_step_that_is_out_of_date(): void {
		update_option( self::LOCK, (string) ( time() + 100 ) );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'is_current_step' )->willReturn( false );
		$post_api->expects( $this->never() )->method( 'pull_story_cron' );

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertSame( array(), tests_wp_scheduled_events() );
	}

	/**
	 * Core rejects an identical event within ten minutes. Another blocked
	 * copy of the step already queued it.
	 */
	public function test_a_held_lock_never_fails_the_pull_when_the_retry_is_not_scheduled(): void {
		update_option( self::LOCK, (string) ( time() + 100 ) );
		tests_wp_fail_next_schedule();
		tests_wp_fail_next_schedule();

		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->never() )->method( 'pull_story_failed' );

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertSame( array(), tests_wp_scheduled_events() );
	}

	/**
	 * A killed step never releases its lock.
	 */
	public function test_an_expired_lock_is_taken_over(): void {
		update_option( self::LOCK, (string) ( time() - 1 ) );

		$post_api = $this->retrying_post_api();
		$post_api->expects( $this->once() )->method( 'pull_story_cron' );

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertFalse( get_option( self::LOCK ) );
	}

	/**
	 * An older event of the same pull, or one that ran after the pull ended.
	 */
	public function test_a_step_that_is_out_of_date_does_not_run(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_step_limit' )->willReturn( 200 );
		$post_api->method( 'is_current_step' )->willReturn( false );
		$post_api->expects( $this->never() )->method( 'pull_story_cron' );
		$post_api->expects( $this->never() )->method( 'pull_story_failed' );

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertSame( array(), tests_wp_scheduled_events() );
		$this->assertFalse( get_option( self::LOCK ) );
	}

	public function test_the_lock_is_released_when_the_step_fails(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_step_limit' )->willReturn( 200 );
		$post_api->method( 'is_current_step' )->willReturn( true );
		$post_api->method( 'pull_story_cron' )->willReturn( new WP_Error( 'chunk', 'Short range.' ) );
		$post_api->expects( $this->once() )->method( 'pull_story_failed' )->willReturnCallback(
			function (): void {
				$this->assertNotFalse( get_option( self::LOCK ), 'Held while the pull is failed' );
			}
		);

		$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );

		$this->assertFalse( get_option( self::LOCK ) );
	}

	public function test_the_lock_is_released_when_the_step_throws(): void {
		$post_api = $this->retrying_post_api();
		$post_api->method( 'pull_story_cron' )->willThrowException( new \RuntimeException( 'Fatal in a step.' ) );

		try {
			$this->make_cron( $post_api )->pull_story_cron( wp_json_encode( $this->task() ) );
			$this->fail( 'The exception reaches the cron runner' );
		} catch ( \RuntimeException $e ) {
			$this->assertFalse( get_option( self::LOCK ) );
		}
	}

	public function test_the_watch_interval_is_five_minutes(): void {
		$schedules = $this->make_cron( $this->createMock( PostAPI::class ) )->add_cron_schedules( array() );

		$this->assertSame( 300, $schedules[ Cron::WATCH_INTERVAL ]['interval'] );
	}

	public function test_the_watch_event_sweeps_pulls_in_flight(): void {
		$watch = $this->createMock( StoryPullWatch::class );
		$watch->expects( $this->once() )->method( 'sweep' );

		$this->make_cron( $this->createMock( PostAPI::class ), $watch )->pull_watch_cron();
	}

	public function test_the_watch_hooks_are_registered(): void {
		$this->make_cron( $this->createMock( PostAPI::class ) )->init();

		$this->assertNotSame( array(), tests_wp_hook_callbacks( Cron::PULL_HOOK ) );
		$this->assertNotSame( array(), tests_wp_hook_callbacks( Cron::WATCH_HOOK ) );
		$this->assertNotSame( array(), tests_wp_hook_callbacks( 'cron_schedules' ) );
	}

	/**
	 * A PostAPI whose step asks for another step in 5 seconds.
	 *
	 * @return PostAPI&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function retrying_post_api(): PostAPI {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'get_step_limit' )->willReturn( 200 );
		$post_api->method( 'is_current_step' )->willReturn( true );
		$post_api->method( 'pull_story_cron' )->willReturn( new WP_Error( 'retry', 'File download not ready.', 5 ) );
		return $post_api;
	}

	private function task(): StoryUpdateTask {
		return new StoryUpdateTask( 7, 'aBc123', '12345', 'draft', 'https://api.example.test/download/1', 'vip://wp-content/uploads/shorthand/7/aBc123_12345' );
	}

	private function make_cron( PostAPI $post_api, ?StoryPullWatch $watch = null ): Cron {
		$dependencies = $this->createMock( Dependencies::class );
		$dependencies->method( 'get_post_api' )->willReturn( $post_api );
		$dependencies->method( 'get_pull_watch' )->willReturn( $watch ?? $this->createMock( StoryPullWatch::class ) );
		$dependencies->method( 'get_pull_lock' )->willReturn( new StoryPullLock() );

		return new Cron( $dependencies );
	}
}
