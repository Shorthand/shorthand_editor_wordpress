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

	/**
	 * Options of each download request, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	/**
	 * Bytes the transport leaves out of each file it streams into.
	 *
	 * @var int
	 */
	private $short = 0;

	/**
	 * Body of a download request with no Range header.
	 *
	 * @var string
	 */
	private $archive = '';

	/** @var bool */
	private $single = false;

	/** @var string */
	private $temp_root;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );
		tests_wp_set_post( 7, (object) array( 'ID' => 7 ) );
		tests_wp_set_post_meta( 7, 'story_id', 'aBc123' );

		$this->temp_root = sys_get_temp_dir() . '/sh_pull_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
		$this->temp_root = (string) realpath( $this->temp_root );
		tests_wp_set_temp_dir( $this->temp_root );

		$this->uploads = new FakeUploads();
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->temp_root . '/*' ) as $path ) {
			unlink( (string) $path );
		}
		rmdir( $this->temp_root );

		parent::tearDown();
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

	/**
	 * A delete cannot tell a chunk already gone from one the host refused. A
	 * record kept until every delete succeeds would be swept on every pull,
	 * for good.
	 */
	public function test_a_superseded_pull_is_forgotten_when_a_chunk_is_already_gone(): void {
		$stale = 'vip://wp-content/uploads/shorthand/7/aBc123_11111';

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
	 * The transport streams into a local temp file, and uploads takes the
	 * chunk only once it is whole, through `Uploads::write()`.
	 */
	public function test_a_chunk_is_received_locally_and_stored_through_uploads(): void {
		$this->chunk_responses = array( array( 'response' => array( 'code' => 206 ) ) );

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 3 * 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB;

		$this->assertSame( 'retry', $this->make_post_api()->pull_story_cron( $task )->get_error_code() );

		$chunk    = 'vip://wp-content/uploads/shorthand/7/aBc123_' . $task->request_nonce . '_0.part';
		$received = $this->requests[0]['filename'];

		$this->assertSame( array( $chunk ), array_keys( $this->uploads->objects() ) );
		$this->assertSame( 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB, strlen( $this->uploads->objects()[ $chunk ] ) );
		$this->assertSame( 1, $this->uploads->writes() );
		$this->assertStringStartsWith( $this->temp_root . '/', $received );
		$this->assertContains( $received, tests_wp_deleted_files() );
	}

	/**
	 * A chunk can run on a server where `pull_story_begin()` did not, so the
	 * directory made then may be missing.
	 */
	public function test_a_chunk_makes_its_directory_before_it_is_stored(): void {
		$task = $this->begin_pull();
		$this->uploads->reset_counts();

		$this->assertSame( 'retry', $this->pull_chunk( $task )->get_error_code() );
		$this->assertSame( 1, $this->uploads->make_dir_calls() );
		$this->assertSame( 1, $this->uploads->writes() );
	}

	/**
	 * Only the write decides whether a chunk was stored.
	 */
	public function test_a_chunk_is_stored_when_its_directory_is_refused(): void {
		$task = $this->begin_pull();
		$this->uploads->fail_make_dir();

		$this->assertSame( 'retry', $this->pull_chunk( $task )->get_error_code() );
		$this->assertCount( 1, $this->uploads->objects() );
	}

	/**
	 * Core's 5 s default would need 1 MB/s to move one chunk.
	 */
	public function test_a_chunk_request_allows_thirty_seconds(): void {
		$this->pull_one_chunk();

		$this->assertSame( PostAPI::CHUNK_TIMEOUT, $this->requests[0]['timeout'] );
	}

	public function test_the_chunk_timeout_can_be_filtered(): void {
		add_filter(
			'theshed_story_download_timeout',
			static function (): int {
				return 45;
			}
		);

		$this->pull_one_chunk();

		$this->assertSame( 45, $this->requests[0]['timeout'] );
	}

	/**
	 * The HTTP transport does not check its writes to the file, so a full
	 * disk leaves a short file behind a 206.
	 */
	public function test_a_chunk_received_short_is_not_stored(): void {
		$this->short = 1;

		$result = $this->pull_one_chunk();

		$this->assertSame( 'file', $result->get_error_code() );
		$this->assertSame( array(), $this->uploads->objects() );
		$this->assertContains( $this->requests[0]['filename'], tests_wp_deleted_files() );
	}

	/**
	 * The last chunk asks past the end; only the bytes left are expected.
	 */
	public function test_the_last_chunk_expects_only_the_bytes_left(): void {
		$this->chunk_responses = array( array( 'response' => array( 'code' => 206 ) ) );

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB + 10;
		$task->files    = 1;
		$task->start    = 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB;
		$task->end      = 2 * $task->start;

		/* The server sends the 10 bytes left, not the range asked for. */
		$this->short = $task->end - $task->size;

		$this->assertSame( 'retry', $this->make_post_api()->pull_story_cron( $task )->get_error_code() );
		$this->assertSame( 1, $this->uploads->writes() );
	}

	/**
	 * The archive never goes into uploads: it is received into a local file
	 * and unpacked from there.
	 */
	public function test_a_single_download_asks_for_the_whole_archive_and_stores_no_chunk(): void {
		$this->single  = true;
		$this->archive = 'not a zip';

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = strlen( $this->archive );

		$this->chunk_responses = array( array( 'response' => array( 'code' => 200 ) ) );

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertArrayNotHasKey( 'Range', $this->requests[0]['headers'] ?? array() );
		$this->assertSame( PostAPI::ARCHIVE_TIMEOUT, $this->requests[0]['timeout'] );
		$this->assertSame( 0, $this->uploads->writes() );
		$this->assertContains( $this->requests[0]['filename'], tests_wp_deleted_files() );
	}

	public function test_a_single_download_received_short_publishes_nothing(): void {
		$this->single  = true;
		$this->archive = 'archive';

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 100;

		$this->chunk_responses = array( array( 'response' => array( 'code' => 200 ) ) );

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'file', $result->get_error_code() );
		$this->assertSame( 0, $this->uploads->writes() );
	}

	/**
	 * `wp_tempnam()` answers with a path even where it made no file. Requested
	 * anyway, the download fails as "Shorthand is not available", which sends
	 * the author after the wrong fault.
	 */
	public function test_a_chunk_with_no_local_file_to_receive_into_is_not_requested(): void {
		$task = $this->begin_pull();

		tests_wp_set_temp_writable( false );

		$result = $this->pull_chunk( $task );

		$this->assertSame( 'file', $result->get_error_code() );
		$this->assertStringContainsString( $this->temp_root, $result->get_error_message() );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 0, $this->uploads->writes() );
	}

	public function test_a_single_download_with_no_local_file_to_receive_into_is_not_requested(): void {
		$this->single = true;

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 100;

		tests_wp_set_temp_writable( false );

		$result = $this->make_post_api()->pull_story_cron( $task );

		$this->assertSame( 'file', $result->get_error_code() );
		$this->assertStringContainsString( $this->temp_root, $result->get_error_message() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A pull that stored a chunk before the option was turned on is not
	 * started again.
	 */
	public function test_a_pull_begun_in_chunks_finishes_in_chunks(): void {
		$this->single = true;

		$this->chunk_responses = array( array( 'response' => array( 'code' => 206 ) ) );

		$task           = $this->begin_pull();
		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 3 * 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB;
		$task->files    = 1;
		$task->start    = 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB;
		$task->end      = 2 * $task->start;

		$this->assertSame( 'retry', $this->make_post_api()->pull_story_cron( $task )->get_error_code() );
		$this->assertArrayHasKey( 'Range', $this->requests[0]['headers'] );
	}

	/**
	 * Pulls the first chunk of a three-chunk download.
	 *
	 * @return \WP_Error The retry for the next chunk, or the failure.
	 */
	private function pull_one_chunk(): \WP_Error {
		return $this->pull_chunk( $this->begin_pull() );
	}

	/**
	 * Pulls the first chunk of a three-chunk download already begun.
	 *
	 * @param StoryUpdateTask $task The begun pull.
	 * @return \WP_Error The retry for the next chunk, or the failure.
	 */
	private function pull_chunk( StoryUpdateTask $task ): \WP_Error {
		$this->chunk_responses = array( array( 'response' => array( 'code' => 206 ) ) );

		$task->file_url = 'https://api.example.test/file/1';
		$task->size     = 3 * 1024 * 1024 * StoryUpdateTask::CHUNK_SIZE_MB;

		return $this->make_post_api()->pull_story_cron( $task );
	}

	public function test_a_chunk_uploads_refuses_leaves_no_file_behind(): void {
		$this->uploads->fail_writes( new \WP_Error( 'file', 'Refused.' ), 1 );

		$this->fail_second_chunk( array( 'response' => array( 'code' => 206 ) ) );

		$this->assertSame( array(), $this->uploads->objects() );
	}

	/**
	 * `story_pulls` counts only chunks that were stored, so a chunk that
	 * failed must leave nothing in uploads for cleanup to miss.
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
		$options->method( 'is_single_download' )->willReturn( $this->single );

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

				$this->requests[] = $options;

				$body = $this->archive;

				if ( isset( $options['headers']['Range'] ) && preg_match( '/^bytes=(\d+)-(\d+)$/', $options['headers']['Range'], $range ) ) {
					$body = str_repeat( 'c', (int) $range[2] - (int) $range[1] + 1 );
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Stands in for the HTTP transport streaming to a local file.
				file_put_contents( $options['filename'], substr( $body, 0, max( 0, strlen( $body ) - $this->short ) ) );

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
