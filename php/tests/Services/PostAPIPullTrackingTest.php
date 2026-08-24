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
use Shorthand\Tests\Support\FakeRemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;

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

		$post_api = new PostAPI(
			$shorthand,
			$options,
			$this->createMock( Permissions::class ),
			'tse_story',
			$auth,
			$this->createMock( StoryContentTransformer::class ),
			$this->file_system,
			new StoryTextExtractor()
		);

		$task = $post_api->pull_story_begin( 7 );

		$this->assertInstanceOf( StoryUpdateTask::class, $task );

		return $task;
	}
}
