<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\WordPressTestCase;

final class StoryUpdateTaskTest extends WordPressTestCase {

	public function test_ensure_chunk_window_sets_the_default_chunk_size(): void {
		$task = $this->make_task();

		$task->ensure_chunk_window();

		$this->assertSame( 5 * 1024 * 1024, $task->end );
	}

	public function test_mark_chunk_downloaded_advances_the_window_and_file_count(): void {
		$task        = $this->make_task();
		$task->start = 0;
		$task->end   = 512;

		$task->mark_chunk_downloaded();

		$this->assertSame( 1, $task->files );
		$this->assertSame( 512, $task->start );
		$this->assertSame( 1024, $task->end );
	}

	public function test_get_progress_percent_caps_progress_to_the_requested_limit(): void {
		$task        = $this->make_task();
		$task->size  = 10;
		$task->start = 15;

		$this->assertSame( 90, $task->get_progress_percent( 90 ) );
	}

	public function test_is_download_complete_requires_download_progress_to_have_started(): void {
		$task       = $this->make_task();
		$task->size = 100;

		$this->assertFalse( $task->is_download_complete() );

		$task->start = 100;

		$this->assertTrue( $task->is_download_complete() );
	}

	public function test_from_json_restores_serialised_state(): void {
		$task = StoryUpdateTask::from_json(
			'{"post_id":7,"story_id":"story-123","request_nonce":"abc","prior_status":"draft","download_url":"https:\/\/example.test\/download","storage_path":"\/tmp\/story","content_version":4,"file_url":"https:\/\/example.test\/file.zip","size":2048,"start":1024,"end":2048,"files":2}'
		);

		$this->assertInstanceOf( StoryUpdateTask::class, $task );
		$this->assertSame( 4, $task->content_version );
		$this->assertSame( 'https://example.test/file.zip', $task->file_url );
		$this->assertSame( 2048, $task->size );
		$this->assertSame( 1024, $task->start );
		$this->assertSame( 2048, $task->end );
		$this->assertSame( 2, $task->files );
	}

	private function make_task(): StoryUpdateTask {
		return new StoryUpdateTask(
			7,
			'story-123',
			'abc',
			'draft',
			'https://example.test/download',
			'/tmp/story'
		);
	}
}
