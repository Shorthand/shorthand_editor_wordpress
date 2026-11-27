<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use RuntimeException;
use Shorthand\Plugin\Dependencies;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Cron;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Services\StoryCover;
use Shorthand\Services\StoryPullLock;
use Shorthand\Services\StoryPullSpeed;
use Shorthand\Services\StoryPullWatch;
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

/**
 * One download step of a pull: several parts within a time budget.
 *
 * A fake server answers range requests from an in-memory archive and writes
 * each part into a `FakeRemoteFileSystem`, as a streamed download would.
 * Each request moves a fake clock on by `$seconds_per_range`, plus the
 * part's length at `$bytes_per_second`.
 */
final class PostAPIPullStepTest extends WordPressTestCase {

	private const PULL_DIR = 'vip://wp-content/uploads/shorthand/7/aBc123_12345';

	/**
	 * Uploads directory the ranges are written to.
	 *
	 * @var \Shorthand\Tests\Support\FakeRemoteFileSystem
	 */
	private $file_system;

	/**
	 * Fake clock, in seconds.
	 *
	 * @var float
	 */
	private $now = 1000.0;

	/**
	 * Time each range request takes.
	 *
	 * @var float
	 */
	private $seconds_per_range = 0.0;

	/**
	 * Link speed of the fake server; 0 adds no time.
	 *
	 * @var int
	 */
	private $bytes_per_second = 0;

	/**
	 * Archive the fake server serves.
	 *
	 * @var string
	 */
	private $archive = '';

	/**
	 * Length of an archive of `x` bytes, served without holding it; 0 serves `$archive`.
	 *
	 * @var int
	 */
	private $archive_size = 0;

	/**
	 * Faults to apply to the next responses, in order: `short`, `wrong_start`,
	 * `timeout`, `refused` or `die`.
	 *
	 * @var string[]
	 */
	private $faults = array();

	/**
	 * Bytes a `timeout` or `die` fault writes before it stops.
	 *
	 * @var int
	 */
	private $partial_bytes = 0;

	/**
	 * Stores the download speed between pulls.
	 *
	 * @var \Shorthand\Services\StoryPullSpeed
	 */
	private $pull_speed;

	/**
	 * Options of each range request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	/**
	 * Runs while each range request is in flight, as another request would.
	 *
	 * @var callable|null
	 */
	private $on_request = null;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_post( 7, (object) array( 'ID' => 7 ) );
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 10 );

		$this->file_system = new FakeRemoteFileSystem();
		$this->pull_speed  = new StoryPullSpeed();
	}

	protected function tearDown(): void {
		$this->archive     = '';
		$this->file_system = null;

		parent::tearDown();
	}

	/**
	 * A host that ends requests at 20 s of wall time must not kill a step
	 * that started a part just before the budget ran out.
	 */
	public function test_a_step_starts_no_part_it_expects_to_end_past_the_budget(): void {
		$this->archive           = str_repeat( 'x', 100 );
		$this->seconds_per_range = 5.0;
		$task                    = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data( 'retry' ) );
		$this->assertSame( 2, $task->files, 'Ranges end at 5 and 10 s; a third would end at 15 s, not inside the 15 s budget' );
		$this->assertSame( 20, $task->start );
		$this->assertSame( 2, get_post_meta( 7, 'story_pulls', true )['12345']['files'] );
		$this->assertSame( 18.0, get_post_meta( 7, 'story_update_state', true )['percent'] );
	}

	/**
	 * A killed step keeps its parts: the watch resumes the stored task, so
	 * it must name the next part, not the one the step began with.
	 */
	public function test_each_part_stores_the_advanced_task(): void {
		$this->archive           = str_repeat( 'x', 100 );
		$this->seconds_per_range = 5.0;
		$post_api                = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $this->task() ), 1 );
		$task = $this->task();

		$post_api->pull_story_cron( $task );

		$stored = $post_api->get_story_update_task( 7 );
		$this->assertSame( wp_json_encode( $task ), $stored['json'] );
		$this->assertSame( 20, StoryUpdateTask::from_json( $stored['json'] )->start );
		$this->assertSame( 0, $stored['attempts'], 'A step that advanced may be resumed again' );
	}

	/**
	 * A paused step that wakes after a newer pull or another run of the step
	 * took over must not retry into the shared part file, delete it, or
	 * record anything.
	 */
	public function test_a_step_that_lost_the_pull_during_a_part_stops_without_writing(): void {
		$this->archive       = str_repeat( 'x', 100 );
		$this->faults        = array( 'timeout' );
		$this->partial_bytes = 4;
		$task                = $this->task();
		$guard_calls         = 0;

		$result = $this->make_post_api()->pull_story_cron(
			$task,
			static function () use ( &$guard_calls ): bool {
				++$guard_calls;
				return false;
			}
		);

		$this->assertNull( $result );
		$this->assertSame( 1, $guard_calls );
		$this->assertCount( 1, $this->requests, 'No retry' );
		$this->assertSame( 0, $task->files );
		$this->assertSame( '', get_post_meta( 7, 'story_pulls', true ) );
		$this->assertSame( '', get_post_meta( 7, 'story_update_task', true ) );
		$this->assertSame( '', get_post_meta( 7, 'story_update_state', true ) );
		$this->assertSame( array( self::PULL_DIR . '/file-0.part' => 'xxxx' ), $this->file_system->objects(), 'The part file may be the other step\'s, so it is left alone' );
	}

	/**
	 * Without a guard from Cron, a step still stops once a newer pull moved the nonce.
	 */
	public function test_a_step_stops_after_the_part_when_a_newer_pull_started(): void {
		$this->archive    = str_repeat( 'x', 100 );
		$task             = $this->task();
		$this->on_request = static function (): void {
			update_post_meta( 7, 'story_update_nonce', '67890' );
		};

		$this->assertNull( $this->make_post_api()->pull_story_cron( $task ) );
		$this->assertSame( 0, $task->files );
		$this->assertSame( '', get_post_meta( 7, 'story_pulls', true ) );
	}

	/**
	 * A step superseded after some parts landed stores no speed.
	 */
	public function test_a_step_superseded_after_some_parts_stores_no_speed(): void {
		$this->archive           = str_repeat( 'x', 100 );
		$this->seconds_per_range = 1.0;
		$this->pull_speed        = $this->createMock( StoryPullSpeed::class );
		$this->pull_speed->expects( $this->never() )->method( 'record' );
		$task  = $this->task();
		$calls = 0;

		$result = $this->make_post_api()->pull_story_cron(
			$task,
			static function () use ( &$calls ): bool {
				return ++$calls < 3;
			}
		);

		$this->assertNull( $result );
		$this->assertSame( 2, $task->files );
	}

	/**
	 * With no archive size, the window can be wider than what arrived. The
	 * speed counts what arrived.
	 */
	public function test_with_no_archive_size_the_speed_counts_bytes_received(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		$this->archive          = str_repeat( 'x', 300000 );
		$this->bytes_per_second = 100000;
		$this->pull_speed       = $this->createMock( StoryPullSpeed::class );
		$this->pull_speed->expects( $this->once() )->method( 'record' )->with( 100000 );
		$task       = $this->task();
		$task->size = 0;

		$this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 1, $task->files );
	}

	/**
	 * The archive is assembled in a step of its own, with a fresh budget.
	 * The last range is shorter than the window.
	 */
	public function test_a_step_that_finishes_the_download_leaves_the_assembly_to_the_next_step(): void {
		$this->archive = 'abcdefghijklmnopqrstuvwxy';
		$task          = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data( 'retry' ) );
		$this->assertTrue( $task->is_download_complete() );
		$this->assertSame(
			array(
				self::PULL_DIR . '/file-0.part' => 'abcdefghij',
				self::PULL_DIR . '/file-1.part' => 'klmnopqrst',
				self::PULL_DIR . '/file-2.part' => 'uvwxy',
			),
			$this->file_system->objects()
		);
		$this->assertSame( 3, get_post_meta( 7, 'story_pulls', true )['12345']['files'], 'The pull is not cleaned up yet' );
		$this->assertSame( 'Saving story to WordPress', get_post_meta( 7, 'story_update_state', true )['status'] );
	}

	public function test_a_budget_of_zero_downloads_one_range_per_step(): void {
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 0 );
		$this->archive = str_repeat( 'x', 100 );
		$task          = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertSame( 1, $task->files );
	}

	public function test_the_budget_is_capped_at_half_the_execution_time(): void {
		$limit = (int) ini_get( 'max_execution_time' );
		set_time_limit( 10 );

		try {
			$budget = $this->make_post_api()->get_step_budget();
		} finally {
			set_time_limit( $limit );
		}

		$this->assertSame( 5.0, $budget );
	}

	public function test_a_negative_budget_counts_as_zero(): void {
		tests_wp_set_filter_value( 'theshed_pull_step_budget', -5 );

		$this->assertSame( 0.0, $this->make_post_api()->get_step_budget() );
	}

	public function test_a_range_request_allows_30_seconds(): void {
		$this->archive = str_repeat( 'x', 10 );

		$this->make_post_api()->pull_story_cron( $this->task() );

		$this->assertSame( 30, $this->requests[0]['timeout'] );
		$this->assertSame( 'bytes=0-9', $this->requests[0]['headers']['Range'] );
	}

	public function test_a_range_with_the_wrong_start_is_fetched_again(): void {
		$this->archive = str_repeat( 'x', 100 );
		$this->faults  = array( 'wrong_start' );
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 0 );
		$task = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 1, $task->files );
	}

	public function test_a_range_short_twice_fails_the_step_and_deletes_the_chunk(): void {
		$this->archive = str_repeat( 'x', 100 );
		$this->faults  = array( 'short', 'short' );
		$task          = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'chunk', $result->get_error_code() );
		$this->assertSame( 'Story chunk is 9 bytes; it should be 10 bytes.', $result->get_error_message() );
		$this->assertSame( 'The story download was incomplete. Click Publish to try again.', $result->get_error_message( 'pretty' ), 'A first publish returns to draft, where the button reads Publish' );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 0, $task->files );
		$this->assertSame( array(), $this->file_system->objects() );
	}

	public function test_a_range_short_twice_on_a_published_post_says_click_update(): void {
		$this->archive      = str_repeat( 'x', 100 );
		$this->faults       = array( 'short', 'short' );
		$task               = $this->task();
		$task->prior_status = 'publish';

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'The story download was incomplete. Click Update to try again.', $result->get_error_message( 'pretty' ) );
	}

	public function test_a_file_system_that_cannot_report_sizes_does_not_fail_the_pull(): void {
		$this->archive                    = str_repeat( 'x', 100 );
		$this->file_system->reports_sizes = false;
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 0 );
		$task = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 1, $task->files );
	}

	/**
	 * The stored task is written once per step, before the step's ranges.
	 * `story_pulls` counts each range, so a step killed after several still
	 * leaves nothing behind.
	 */
	public function test_abandoning_a_pull_after_a_step_of_several_ranges_deletes_every_chunk(): void {
		tests_wp_set_post(
			7,
			(object) array(
				'ID'          => 7,
				'post_status' => 'draft',
			)
		);
		$this->archive = str_repeat( 'x', 100 );
		$task          = $this->task();
		$post_api      = $this->make_post_api();
		$post_api->set_story_update_task( 7, wp_json_encode( $task ) );

		$post_api->pull_story_cron( $task );
		$this->file_system->put( self::PULL_DIR . '/file-' . $task->files . '.part', 'partial' );
		$this->assertCount( 11, $this->file_system->objects() );

		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ) );

		$this->assertSame( array(), $this->file_system->objects() );
		$this->assertSame( '', get_post_meta( 7, 'story_pulls', true ) );
	}

	/**
	 * A resumed step starts again from the stored task, below the chunks
	 * the killed step left. Dying again must still leave nothing behind.
	 */
	public function test_abandoning_a_resumed_pull_deletes_the_chunks_of_the_step_before_it(): void {
		$this->archive = str_repeat( 'x', 100 );
		$stored        = wp_json_encode( $this->task() );
		$post_api      = $this->make_post_api();
		$post_api->set_story_update_task( 7, $stored );

		tests_wp_set_filter_value( 'theshed_pull_step_budget', 4.5 );
		$this->seconds_per_range = 1.0;
		$post_api->pull_story_cron( $this->task() );
		$this->assertCount( 4, $this->file_system->objects() );

		tests_wp_set_filter_value( 'theshed_pull_step_budget', 0 );
		$post_api->pull_story_cron( StoryUpdateTask::from_json( $stored ) );
		$this->assertSame( 4, get_post_meta( 7, 'story_pulls', true )['12345']['files'] );

		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ) );

		$this->assertSame( array(), $this->file_system->objects() );
	}

	public function test_a_resumed_step_that_fails_deletes_the_chunks_of_the_step_before_it(): void {
		$this->archive = str_repeat( 'x', 100 );
		$stored        = wp_json_encode( $this->task() );
		$post_api      = $this->make_post_api();

		tests_wp_set_filter_value( 'theshed_pull_step_budget', 4.5 );
		$this->seconds_per_range = 1.0;
		$post_api->pull_story_cron( $this->task() );

		$this->faults = array( 'short', 'short' );
		$resumed      = StoryUpdateTask::from_json( $stored );
		$result       = $post_api->pull_story_cron( $resumed );
		$this->assertSame( 'chunk', $result->get_error_code() );

		$post_api->pull_story_failed( $resumed, $result );

		$this->assertSame( array(), $this->file_system->objects() );
		$this->assertSame( '', get_post_meta( 7, 'story_pulls', true ) );
	}

	/**
	 * The first part is a 5 MiB probe; at 58 Mbit/s the next is the 25 MiB cap.
	 */
	public function test_a_step_probes_then_grows_the_part_to_the_cap(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		$this->archive_size     = 31 * MB_IN_BYTES;
		$this->bytes_per_second = 7250000;
		$task                   = $this->task();

		$this->make_post_api()->pull_story_cron( $task );

		$this->assertSame(
			array( 'bytes=0-5242879', 'bytes=5242880-31457279', 'bytes=31457280-32505855' ),
			$this->ranges()
		);
		$this->assertTrue( $task->is_download_complete() );
	}

	/**
	 * At 7 Mbit/s a part takes 10 s at about 8.3 MiB.
	 */
	public function test_parts_settle_near_8_mib_at_7_mbit(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 30 );
		$this->archive_size     = 40000000;
		$this->bytes_per_second = 875000;

		$this->make_post_api()->pull_story_cron( $this->task() );

		$widths = $this->widths();
		$this->assertCount( 3, $widths, 'Parts end at 6, 16 and 26 s; a fourth would end past the 30 s budget' );
		$this->assertSame( 5 * MB_IN_BYTES, $widths[0] );
		$this->assertEqualsWithDelta( 8750000, $widths[1], 20 );
		$this->assertEqualsWithDelta( 8750000, $widths[2], 20 );
	}

	/**
	 * At 2 Mbit/s the probe fills the first step. The speed it measured
	 * sizes the next step's parts through the stored task.
	 */
	public function test_the_speed_carries_over_to_the_next_step_through_the_task(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 30 );
		$this->archive_size     = 20000000;
		$this->bytes_per_second = 250000;
		$task                   = $this->task();
		$post_api               = $this->make_post_api();

		$post_api->pull_story_cron( $task );
		$this->assertSame( array( 5 * MB_IN_BYTES ), $this->widths() );

		$next = StoryUpdateTask::from_json( wp_json_encode( $task ) );
		$this->assertEqualsWithDelta( 250000, $next->speed, 1 );

		$this->requests = array();
		$post_api->pull_story_cron( $next );

		/* The measured speed floors to just under 250 kB/s, so the parts take just under 10 s each. */
		$widths = $this->widths();
		$this->assertGreaterThanOrEqual( 2, count( $widths ) );
		foreach ( $widths as $width ) {
			$this->assertEqualsWithDelta( 2500000, $width, 20 );
		}
	}

	public function test_the_first_part_is_sized_from_the_stored_site_speed(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 0 );
		$this->archive_size = 9000000;
		$this->pull_speed   = $this->createMock( StoryPullSpeed::class );
		$this->pull_speed->expects( $this->once() )->method( 'get' )->willReturn( 300000 );

		$this->make_post_api()->pull_story_cron( $this->task() );

		$this->assertSame( array( 'bytes=0-2999999' ), $this->ranges() );
	}

	public function test_a_step_stores_the_speed_it_measured_once(): void {
		tests_wp_set_filter_value( 'theshed_pull_step_budget', 4.5 );
		$this->archive           = str_repeat( 'x', 100 );
		$this->seconds_per_range = 1.0;
		$this->pull_speed        = $this->createMock( StoryPullSpeed::class );
		$this->pull_speed->expects( $this->once() )->method( 'record' )->with( 10 );
		$task = $this->task();

		$this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 4, $task->files );
		$this->assertSame( 10, $task->speed );
	}

	public function test_a_step_that_measured_no_time_stores_no_speed(): void {
		$this->archive    = str_repeat( 'x', 100 );
		$this->pull_speed = $this->createMock( StoryPullSpeed::class );
		$this->pull_speed->expects( $this->never() )->method( 'record' );
		$task = $this->task();

		$this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 0, $task->speed );
	}

	/**
	 * The probe timed out after 4.5 MB, so it reached 150 kB/s. The retry
	 * asks for 10 s at that speed, in the same file.
	 */
	public function test_a_part_that_times_out_is_fetched_once_more_at_a_smaller_size(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		$this->archive_size  = 6 * MB_IN_BYTES;
		$this->faults        = array( 'timeout' );
		$this->partial_bytes = 4500000;
		$task                = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertSame( array( 'bytes=0-5242879', 'bytes=0-1499999' ), $this->ranges() );
		$this->assertSame( $this->requests[0]['filename'], $this->requests[1]['filename'] );
		$this->assertSame( 1, $task->files );
		$this->assertSame( 1500000, $task->start );
		$this->assertSame( 150000, $task->speed );
		$this->assertSame( 3000000, $task->end );
		$this->assertSame( 1500000, $this->file_system->get_file_size( self::PULL_DIR . '/file-0.part' ) );
	}

	/**
	 * With no speed known and no readable partial file, half the old speed
	 * is still 0. The retry must not ask for the probe again.
	 */
	public function test_a_probe_that_times_out_with_no_readable_partial_is_retried_at_half_the_size(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		$this->archive_size               = 6 * MB_IN_BYTES;
		$this->faults                     = array( 'timeout' );
		$this->file_system->reports_sizes = false;
		$task                             = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertSame( array( 'bytes=0-5242879', 'bytes=0-2621439' ), $this->ranges() );
		$this->assertSame( 1, $task->files );
	}

	public function test_a_part_that_times_out_twice_fails_the_step_and_deletes_the_chunk(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		$this->archive_size  = 6 * MB_IN_BYTES;
		$this->faults        = array( 'timeout', 'timeout' );
		$this->partial_bytes = 1000;
		$task                = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 0, $task->files );
		$this->assertSame( array(), $this->file_system->objects() );
	}

	/**
	 * A bad `Content-Range` and a timeout share the one second try.
	 */
	public function test_a_timeout_after_a_bad_range_is_not_retried(): void {
		$this->archive = str_repeat( 'x', 100 );
		$this->faults  = array( 'wrong_start', 'timeout' );
		$task          = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( array(), $this->file_system->objects() );
	}

	public function test_other_transport_errors_are_not_retried(): void {
		$this->archive = str_repeat( 'x', 100 );
		$this->faults  = array( 'refused' );
		$task          = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'cURL error 7', $result->get_error_message() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 0, $task->files );
		$this->assertSame( array(), $this->file_system->objects(), 'A part that is not retried is deleted too' );
	}

	/**
	 * A step resized a part after a timeout. The stored task must still be
	 * the exact event argument, and a resume of it must download the parts
	 * where the resized one left off.
	 */
	public function test_a_resume_after_a_resized_part_runs_the_stored_step(): void {
		tests_wp_set_filter_value( 'theshed_pull_chunk_size', 0 );
		$this->archive       = substr( str_repeat( implode( '', range( 'a', 'z' ) ), 160000 ), 0, 4000000 );
		$this->faults        = array( 'timeout' );
		$this->partial_bytes = 3600000;
		$post_api            = $this->make_post_api();
		$cron                = $this->make_cron( $post_api );
		$first               = wp_json_encode( $this->task() );
		$post_api->set_story_update_task( 7, $first );

		$cron->pull_story_cron( $first );

		$stored = $post_api->get_story_update_task( 7 )['json'];
		$this->assertSame( array( $stored ), end( $GLOBALS['tests_wp_state']['cron_events'] )['args'], 'The stored task is the scheduled argument' );
		$step = StoryUpdateTask::from_json( $stored );
		$this->assertSame( 1200000, $step->start );
		$this->assertSame( 2400000, $step->end );
		$this->assertSame( 120000, $step->speed );
		$this->assertSame( 1, $step->files );

		$this->faults        = array( 'die' );
		$this->partial_bytes = 100;
		try {
			$cron->pull_story_cron( $stored );
			$this->fail( 'The step should have been killed' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $stored, $post_api->get_story_update_task( 7 )['json'], 'A killed step leaves its task stored' );
		}

		$this->assertTrue( $post_api->is_current_step( StoryUpdateTask::from_json( $stored ) ) );
		$cron->pull_story_cron( $stored );

		$last = $post_api->get_story_update_task( 7 )['json'];
		$this->assertSame( array( $last ), end( $GLOBALS['tests_wp_state']['cron_events'] )['args'] );
		$this->assertTrue( StoryUpdateTask::from_json( $last )->is_download_complete() );
		$this->assertSame( $this->archive, implode( '', $this->file_system->objects() ), 'The parts join back into the archive' );
	}

	private function task(): StoryUpdateTask {
		$task           = new StoryUpdateTask( 7, 'aBc123', '12345', 'draft', 'https://api.example.test/download/1', self::PULL_DIR );
		$task->file_url = 'https://cdn.example.test/bundle.zip';
		$task->size     = $this->archive_size > 0 ? $this->archive_size : strlen( $this->archive );

		return $task;
	}

	/**
	 * Answers one range request, as Shorthand's CDN would.
	 *
	 * @param array<string, mixed> $options Request options.
	 * @return array<string, mixed>|WP_Error
	 * @throws RuntimeException When a `die` fault kills the step.
	 */
	private function serve( array $options ) {
		$this->requests[] = $options;
		$this->now       += $this->seconds_per_range;

		if ( null !== $this->on_request ) {
			call_user_func( $this->on_request );
		}

		preg_match( '/^bytes=(\d+)-(\d+)$/', $options['headers']['Range'], $match );
		$start = (int) $match[1];
		$fault = array_shift( $this->faults );

		if ( 'refused' === $fault ) {
			/* Requests opens the stream file before it connects. */
			$this->file_system->put( $options['filename'], '' );
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to cdn.example.test port 443: Connection refused' );
		}

		if ( $this->archive_size > 0 ) {
			$body = str_repeat( 'x', max( min( (int) $match[2], $this->archive_size - 1 ) - $start + 1, 0 ) );
		} else {
			$body = substr( $this->archive, $start, (int) $match[2] - $start + 1 );
		}

		if ( 'timeout' === $fault || 'die' === $fault ) {
			$this->file_system->put( $options['filename'], substr( $body, 0, $this->partial_bytes ) );
		}

		if ( 'die' === $fault ) {
			throw new RuntimeException( 'The step was killed.' );
		}

		if ( 'timeout' === $fault ) {
			$this->now += PostAPI::CHUNK_TIMEOUT;
			return new WP_Error( 'http_request_failed', "cURL error 28: Operation timed out after 30000 milliseconds with {$this->partial_bytes} out of " . strlen( $body ) . ' bytes received' );
		}

		if ( $this->bytes_per_second > 0 ) {
			$this->now += strlen( $body ) / $this->bytes_per_second;
		}

		if ( 'short' === $fault ) {
			$body = substr( $body, 0, -1 );
		}

		$range_start = 'wrong_start' === $fault ? $start + 1 : $start;
		$range_end   = $start + strlen( $body ) - 1;
		$total       = $this->archive_size > 0 ? $this->archive_size : strlen( $this->archive );

		$this->file_system->put( $options['filename'], $body );

		return array(
			'response' => array( 'code' => 206 ),
			'headers'  => array( 'Content-Range' => "bytes {$range_start}-{$range_end}/{$total}" ),
			'body'     => '',
		);
	}

	/**
	 * Range header of each request.
	 *
	 * @return string[]
	 */
	private function ranges(): array {
		return array_map(
			static function ( array $request ): string {
				return $request['headers']['Range'];
			},
			$this->requests
		);
	}

	/**
	 * Width of each requested range, in bytes.
	 *
	 * @return int[]
	 */
	private function widths(): array {
		return array_map(
			static function ( string $range ): int {
				preg_match( '/^bytes=(\d+)-(\d+)$/', $range, $match );
				return (int) $match[2] - (int) $match[1] + 1;
			},
			$this->ranges()
		);
	}

	private function make_cron( PostAPI $post_api ): Cron {
		$dependencies = $this->createMock( Dependencies::class );
		$dependencies->method( 'get_post_api' )->willReturn( $post_api );
		$dependencies->method( 'get_pull_watch' )->willReturn( $this->createMock( StoryPullWatch::class ) );
		$dependencies->method( 'get_pull_lock' )->willReturn( new StoryPullLock() );

		return new Cron( $dependencies );
	}

	private function make_post_api(): PostAPI {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturnCallback(
			function ( string $url, string $method, array $options = array() ) {
				return $this->serve( $options );
			}
		);

		return new PostAPI(
			$shorthand,
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class ),
			$this->file_system,
			new StoryTextExtractor(),
			$this->createMock( StoryCover::class ),
			$this->pull_speed,
			function (): float {
				return $this->now;
			}
		);
	}
}
