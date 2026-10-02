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
use Shorthand\Services\Files\BundleStore;
use Shorthand\Tests\Support\FakeUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * Tracking in-flight downloads so their chunks can be removed.
 *
 * A superseded pull returns without cleaning up, and uploads cannot be
 * listed. The `story_pulls` post meta key is the only record of how many
 * chunks it left behind; their paths follow from the nonce.
 */
final class PostAPIPullTrackingTest extends WordPressTestCase {

	/** @var \Shorthand\Tests\Support\FakeUploads */
	private $uploads;

	/**
	 * Responses to chunk requests, in order.
	 *
	 * @var array<int, array<string, mixed>|\WP_Error>
	 */
	private $chunk_responses = array();

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );
		tests_wp_set_post( 7, (object) array( 'ID' => 7 ) );
		tests_wp_set_post_meta( 7, 'story_id', 'aBc123' );

		$this->uploads = new FakeUploads();
	}

	public function test_beginning_a_pull_records_it_with_no_chunks_yet(): void {
		$task = $this->begin_pull();

		$this->assertInstanceOf( StoryUpdateTask::class, $task );
		$this->assertSame(
			array( $task->request_nonce => 0 ),
			get_post_meta( 7, 'story_pulls', true )
		);
	}

	public function test_beginning_a_pull_removes_the_chunks_of_a_superseded_one(): void {
		$stale = 'vip://wp-content/uploads/shorthand/7/aBc123_11111';

		$this->uploads->put( $stale . '_0.part', 'first' );
		$this->uploads->put( $stale . '_1.part', 'second' );

		tests_wp_set_post_meta( 7, 'story_pulls', array( '11111' => 2 ) );

		$task = $this->begin_pull();

		$this->assertSame( array(), $this->uploads->objects() );
		$this->assertSame( 2, $this->uploads->deletes() );
		$this->assertSame( array( (int) $task->request_nonce ), array_keys( get_post_meta( 7, 'story_pulls', true ) ) );
	}

	public function test_a_pull_with_no_downloaded_chunks_is_swept_without_deleting_files(): void {
		tests_wp_set_post_meta( 7, 'story_pulls', array( '11111' => 0 ) );

		$this->begin_pull();

		$this->assertSame( 0, $this->uploads->deletes() );
	}

	/**
	 * A pull already in flight when the plugin was upgraded is recorded in the
	 * older shape, and wrote its chunks at the older naming: a directory beside
	 * the bundle. Sweeping it at the current naming would delete nothing.
	 */
	public function test_a_pull_recorded_before_the_paths_became_derivable_is_swept_at_the_old_naming(): void {
		$stale = 'vip://wp-content/uploads/shorthand/7/aBc123_11111';

		$this->uploads->put( $stale . '/file-0.part', 'first' );
		$this->uploads->put( $stale . '/file-1.part', 'second' );

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

		$this->begin_pull();

		$this->assertSame( array(), $this->uploads->objects() );
	}

	/**
	 * A pull record is data read back from the database, and its key becomes
	 * part of a path. One that is not a path segment is left alone.
	 */
	public function test_a_stale_pull_whose_nonce_is_not_a_path_segment_deletes_nothing(): void {
		tests_wp_set_post_meta( 7, 'story_pulls', array( '../../etc' => 2 ) );

		$this->begin_pull();

		$this->assertSame( 0, $this->uploads->deletes() );
	}

	/**
	 * The sweep runs before the new pull records or writes anything, so a
	 * stale record that happens to share the new nonce holds only stale chunks.
	 */
	public function test_a_stale_pull_that_shares_the_new_nonce_is_swept(): void {
		$stale = 'vip://wp-content/uploads/shorthand/7/aBc123_10000';

		$this->uploads->put( $stale . '_0.part', 'first' );

		tests_wp_set_post_meta( 7, 'story_pulls', array( '10000' => 1 ) );

		$task = $this->begin_pull();

		$this->assertSame( '10000', $task->request_nonce );
		$this->assertSame( array(), $this->uploads->objects() );
	}

	/**
	 * A host that refuses the bundle directory cannot hold the chunks either,
	 * so the pull fails now rather than at the first chunk. Nothing is
	 * recorded, because nothing was written.
	 */
	public function test_a_pull_that_cannot_be_prepared_is_not_recorded(): void {
		$this->uploads->fail_make_dir();

		$result = $this->make_post_api()->pull_story_begin( 7 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', get_post_meta( 7, 'story_pulls', true ) );
	}

	/**
	 * Streaming opens the chunk file before the status is known, so a refused
	 * chunk still leaves one. `story_pulls` counts only chunks that arrived.
	 */
	public function test_a_refused_chunk_leaves_no_file_behind(): void {
		$this->fail_second_chunk( array( 'response' => array( 'code' => 500 ) ) );

		$this->assertSame( array(), $this->uploads->objects() );
	}

	public function test_a_chunk_cut_off_in_transit_leaves_no_file_behind(): void {
		$this->fail_second_chunk( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out.' ) );

		$this->assertSame( array(), $this->uploads->objects() );
	}

	/**
	 * Pulls one chunk, then fails the next as `Cron::pull_story_cron()` does.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response to the second chunk request.
	 */
	private function fail_second_chunk( $response ): void {
		$this->chunk_responses = array( array( 'response' => array( 'code' => 206 ) ), $response );

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 3 * 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB;

		$api = $this->make_post_api();

		$this->assertSame( 'retry', $api->pull_story_cron( $task )->get_error_code() );

		$result = $api->pull_story_cron( $task );

		$this->assertInstanceOf( \WP_Error::class, $result );

		$api->pull_story_failed( $task, $result );
	}

	private function begin_pull(): StoryUpdateTask {
		$task = $this->make_post_api()->pull_story_begin( 7 );

		$this->assertInstanceOf( StoryUpdateTask::class, $task );

		return $task;
	}

	private function make_post_api(): PostAPI {
		$auth = $this->createMock( AuthStateManager::class );
		$auth->method( 'is_connected' )->willReturn( true );

		$options = $this->createMock( Options::class );
		$options->method( 'get_api_url' )->willReturn( 'https://api.example.test' );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'shorthand_api_authed_request' )->willReturnCallback(
			function ( $url, $method = 'GET', $options = array() ) {
				if ( empty( $options['stream'] ) ) {
					return array(
						'response' => array( 'code' => 202 ),
						'headers'  => array( 'Location' => 'https://api.example.test/download/1' ),
						'body'     => '',
					);
				}

				$this->uploads->put( $options['filename'], 'chunk' );

				return array_shift( $this->chunk_responses );
			}
		);

		return new PostAPI(
			$shorthand,
			$options,
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth,
			$this->createMock( StoryContentTransformer::class ),
			new BundleStore( $this->uploads ),
			new StoryTextExtractor(),
			$this->createMock( StoryCover::class )
		);
	}
}
