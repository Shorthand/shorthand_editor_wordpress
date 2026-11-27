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
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

/**
 * One download step of a pull: several ranges within a time budget.
 *
 * A fake server answers range requests from an in-memory archive and writes
 * each range into a `FakeRemoteFileSystem`, as a streamed download would.
 * Each request moves a fake clock on by `$seconds_per_range`.
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
	 * Archive the fake server serves.
	 *
	 * @var string
	 */
	private $archive = '';

	/**
	 * Faults to apply to the next responses, in order: `short` or `wrong_start`.
	 *
	 * @var string[]
	 */
	private $faults = array();

	/**
	 * Options of each range request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_post( 7, (object) array( 'ID' => 7 ) );
		tests_wp_set_post_meta( 7, 'story_update_nonce', '12345' );
		tests_wp_set_filter_value( 'shorthand_pull_chunk_size', 10 );

		$this->file_system = new FakeRemoteFileSystem();
	}

	public function test_a_step_downloads_ranges_until_the_budget_is_spent(): void {
		$this->archive           = str_repeat( 'x', 100 );
		$this->seconds_per_range = 6.0;
		$task                    = $this->task();

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'retry', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data( 'retry' ) );
		$this->assertSame( 4, $task->files, 'Ranges end at 6, 12, 18 and 24 s; the budget is 20 s' );
		$this->assertSame( 40, $task->start );
		$this->assertSame( 4, get_post_meta( 7, 'story_pulls', true )['12345']['files'] );
		$this->assertSame( 36.0, get_post_meta( 7, 'story_update_state', true )['percent'] );
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
		tests_wp_set_filter_value( 'shorthand_pull_step_budget', 0 );
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
		tests_wp_set_filter_value( 'shorthand_pull_step_budget', -5 );

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
		tests_wp_set_filter_value( 'shorthand_pull_step_budget', 0 );
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
		$this->assertSame( 'The story download was incomplete. Click Update to try again.', $result->get_error_message( 'pretty' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 0, $task->files );
		$this->assertSame( array(), $this->file_system->objects() );
	}

	public function test_a_file_system_that_cannot_report_sizes_does_not_fail_the_pull(): void {
		$this->archive                    = str_repeat( 'x', 100 );
		$this->file_system->reports_sizes = false;
		tests_wp_set_filter_value( 'shorthand_pull_step_budget', 0 );
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

		tests_wp_set_filter_value( 'shorthand_pull_step_budget', 3.5 );
		$this->seconds_per_range = 1.0;
		$post_api->pull_story_cron( $this->task() );
		$this->assertCount( 4, $this->file_system->objects() );

		tests_wp_set_filter_value( 'shorthand_pull_step_budget', 0 );
		$post_api->pull_story_cron( StoryUpdateTask::from_json( $stored ) );
		$this->assertSame( 4, get_post_meta( 7, 'story_pulls', true )['12345']['files'] );

		$post_api->abandon_pull( 7, new WP_Error( 'stalled', 'No progress.' ) );

		$this->assertSame( array(), $this->file_system->objects() );
	}

	public function test_a_resumed_step_that_fails_deletes_the_chunks_of_the_step_before_it(): void {
		$this->archive = str_repeat( 'x', 100 );
		$stored        = wp_json_encode( $this->task() );
		$post_api      = $this->make_post_api();

		tests_wp_set_filter_value( 'shorthand_pull_step_budget', 3.5 );
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

	private function task(): StoryUpdateTask {
		$task           = new StoryUpdateTask( 7, 'aBc123', '12345', 'draft', 'https://api.example.test/download/1', self::PULL_DIR );
		$task->file_url = 'https://cdn.example.test/bundle.zip';
		$task->size     = strlen( $this->archive );

		return $task;
	}

	/**
	 * Answers one range request, as Shorthand's CDN would.
	 *
	 * @param array<string, mixed> $options Request options.
	 * @return array<string, mixed>
	 */
	private function serve( array $options ): array {
		$this->requests[] = $options;
		$this->now       += $this->seconds_per_range;

		preg_match( '/^bytes=(\d+)-(\d+)$/', $options['headers']['Range'], $match );
		$start = (int) $match[1];
		$body  = substr( $this->archive, $start, (int) $match[2] - $start + 1 );
		$fault = array_shift( $this->faults );

		if ( 'short' === $fault ) {
			$body = substr( $body, 0, -1 );
		}

		$range_start = 'wrong_start' === $fault ? $start + 1 : $start;
		$range_end   = $start + strlen( $body ) - 1;

		$this->file_system->put( $options['filename'], $body );

		return array(
			'response' => array( 'code' => 206 ),
			'headers'  => array( 'Content-Range' => "bytes {$range_start}-{$range_end}/" . strlen( $this->archive ) ),
			'body'     => '',
		);
	}

	private function make_post_api(): PostAPI {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturnCallback(
			function ( string $url, string $method, array $options = array() ): array {
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
			function (): float {
				return $this->now;
			}
		);
	}
}
