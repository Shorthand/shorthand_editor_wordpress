<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryUpdateTask;
use Shorthand\Tests\WordPressTestCase;

final class StoryUpdateTaskTest extends WordPressTestCase {

	public function test_an_unknown_speed_gives_a_5_mib_probe(): void {
		$task = $this->make_task();

		$task->ensure_chunk_window();

		$this->assertSame( 5 * MB_IN_BYTES, $task->end );
	}

	public function test_the_first_window_uses_a_known_speed(): void {
		$task        = $this->make_task();
		$task->speed = 1000000;

		$task->ensure_chunk_window();

		$this->assertSame( 10000000, $task->end );
	}

	public function test_a_window_takes_ten_seconds_at_the_measured_speed(): void {
		$task        = $this->make_task();
		$task->start = 100;

		$task->size_next_window( 875000 );

		$this->assertSame( 100 + 8750000, $task->end );
	}

	public function test_a_window_is_at_least_1_mib(): void {
		$task = $this->make_task();

		$task->size_next_window( 1000 );

		$this->assertSame( MB_IN_BYTES, $task->end );
	}

	public function test_a_window_is_at_most_the_filtered_cap(): void {
		tests_wp_set_filter_value( 'shorthand_pull_chunk_size', 8 * MB_IN_BYTES );
		$task = $this->make_task();

		$task->size_next_window( 10 * MB_IN_BYTES );

		$this->assertSame( 8 * MB_IN_BYTES, $task->end );
	}

	public function test_a_cap_below_the_probe_and_the_floor_wins(): void {
		tests_wp_set_filter_value( 'shorthand_pull_chunk_size', 512 * 1024 );
		$task = $this->make_task();

		$task->size_next_window( 0 );
		$this->assertSame( 512 * 1024, $task->end, 'The probe obeys the cap' );

		$task->size_next_window( 1000 );
		$this->assertSame( 512 * 1024, $task->end, 'The floor obeys the cap' );
	}

	public function test_a_window_never_passes_the_end_of_the_archive(): void {
		$task        = $this->make_task();
		$task->size  = 3 * MB_IN_BYTES;
		$task->start = 2 * MB_IN_BYTES;

		$task->size_next_window( 0 );
		$this->assertSame( 3 * MB_IN_BYTES, $task->end, 'The probe obeys the archive size' );

		$task->size_next_window( 10 * MB_IN_BYTES );
		$this->assertSame( 3 * MB_IN_BYTES, $task->end );
	}

	public function test_a_window_that_timed_out_shrinks_to_the_speed_it_reached(): void {
		$task        = $this->make_task();
		$task->start = 100;
		$task->end   = 100 + 25 * MB_IN_BYTES;

		$task->shrink_window( 300000 );

		$this->assertSame( 100 + 3000000, $task->end );
	}

	public function test_a_window_that_timed_out_is_at_most_halved(): void {
		$task      = $this->make_task();
		$task->end = 25 * MB_IN_BYTES;

		$task->shrink_window( 10 * MB_IN_BYTES );
		$this->assertSame( 12 * MB_IN_BYTES + 512 * 1024, $task->end, 'A speed that sizes to the cap still halves the window' );

		$task->end = 5 * MB_IN_BYTES;
		$task->shrink_window( 0 );
		$this->assertSame( 5 * MB_IN_BYTES / 2, $task->end, 'An unknown speed does not give the probe again' );
	}

	public function test_a_window_that_timed_out_keeps_the_1_mib_floor(): void {
		$task      = $this->make_task();
		$task->end = MB_IN_BYTES + 10;

		$task->shrink_window( 0 );

		$this->assertSame( MB_IN_BYTES, $task->end );
	}

	public function test_a_chunk_size_below_one_byte_falls_back_to_the_default(): void {
		tests_wp_set_filter_value( 'shorthand_pull_chunk_size', 0 );

		$this->assertSame( 25 * 1024 * 1024, StoryUpdateTask::get_chunk_size() );
	}

	public function test_a_pull_in_flight_keeps_the_window_it_started_with(): void {
		$task        = $this->make_task();
		$task->start = 5 * 1024 * 1024;
		$task->end   = 10 * 1024 * 1024;

		$task->ensure_chunk_window();

		$this->assertSame( 10 * 1024 * 1024, $task->end );
	}

	public function test_the_last_window_expects_only_what_is_left_of_the_archive(): void {
		$task        = $this->make_task();
		$task->size  = 25;
		$task->start = 20;
		$task->end   = 30;

		$this->assertSame( 5, $task->get_expected_chunk_length() );

		$task->start = 10;
		$task->end   = 20;

		$this->assertSame( 10, $task->get_expected_chunk_length() );
	}

	public function test_mark_chunk_downloaded_advances_the_window_and_file_count(): void {
		$task        = $this->make_task();
		$task->start = 0;
		$task->end   = 512;

		$task->mark_chunk_downloaded();

		$this->assertSame( 1, $task->files );
		$this->assertSame( 512, $task->start );
		$this->assertSame( 512, $task->end, 'The caller sizes the next window' );
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
		$this->assertSame( 0, $task->speed );
	}

	public function test_speed_survives_a_round_trip_through_json(): void {
		$task        = $this->make_task();
		$task->speed = 875000;

		$this->assertSame( 875000, StoryUpdateTask::from_json( wp_json_encode( $task ) )->speed );
	}

	/**
	 * Stored speeds that must read as unknown.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function junk_speeds(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
			'string'   => array( '875000' ),
			'float'    => array( 1.5 ),
			'null'     => array( null ),
		);
	}

	/**
	 * A stored speed that is not an int above 0 reads as 0.
	 *
	 * @dataProvider junk_speeds
	 *
	 * @param mixed $speed Stored value.
	 */
	public function test_a_junk_speed_reads_as_unknown( $speed ): void {
		$data          = json_decode( wp_json_encode( $this->make_task() ), true );
		$data['speed'] = $speed;

		$this->assertSame( 0, StoryUpdateTask::from_json( wp_json_encode( $data ) )->speed );
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
