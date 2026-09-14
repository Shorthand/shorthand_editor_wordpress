<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
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

		$post_api = new PostAPI(
			$shorthand,
			$options,
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth,
			$this->createMock( StoryContentTransformer::class ),
			new BundleStore( $this->uploads ),
			new StoryTextExtractor()
		);

		$task = $post_api->pull_story_begin( 7 );

		$this->assertInstanceOf( StoryUpdateTask::class, $task );

		return $task;
	}
}
