<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StorySyncProgress;
use Shorthand\Tests\WordPressTestCase;

final class StorySyncProgressTest extends WordPressTestCase {

	public function test_from_meta_value_restores_percent_and_status(): void {
		$progress = StorySyncProgress::from_meta_value(
			array(
				'percent' => 45,
				'status'  => 'Saving story to WordPress',
			)
		);

		$this->assertInstanceOf( StorySyncProgress::class, $progress );
		$this->assertSame(
			array(
				'percent' => 45.0,
				'status'  => 'Saving story to WordPress',
			),
			$progress->to_array()
		);
	}

	public function test_new_progress_carries_the_time_it_was_written(): void {
		$progress = new StorySyncProgress( 10, 'Requesting story from Shorthand' );

		$this->assertGreaterThanOrEqual( time() - 1, $progress->get_updated_at() );
		$this->assertSame( $progress->get_updated_at(), $progress->to_array()['updated_at'] );
	}

	public function test_from_meta_value_restores_the_heartbeat(): void {
		$progress = StorySyncProgress::from_meta_value(
			array(
				'percent'    => 45,
				'status'     => 'Saving story to WordPress',
				'updated_at' => 1700000000,
			)
		);

		$this->assertSame( 1700000000, $progress->get_updated_at() );
	}

	/**
	 * 1.0.11 wrote no heartbeat. The watch must tell that apart from a
	 * fresh one.
	 */
	public function test_progress_from_a_version_without_a_heartbeat_has_none(): void {
		$progress = StorySyncProgress::from_meta_value( array( 'percent' => 45 ) );

		$this->assertNull( $progress->get_updated_at() );
		$this->assertArrayNotHasKey( 'updated_at', $progress->to_array() );
	}

	public function test_touch_keeps_the_progress_and_stamps_the_time(): void {
		$progress = StorySyncProgress::from_meta_value(
			array(
				'percent'    => 45,
				'status'     => 'Saving story to WordPress',
				'updated_at' => 1700000000,
			)
		)->touch();

		$this->assertSame( 45.0, $progress->to_array()['percent'] );
		$this->assertSame( 'Saving story to WordPress', $progress->to_array()['status'] );
		$this->assertGreaterThanOrEqual( time() - 1, $progress->get_updated_at() );
	}

	public function test_from_meta_value_rejects_invalid_shapes(): void {
		$this->assertNull( StorySyncProgress::from_meta_value( array( 'status' => 'Saving story to WordPress' ) ) );
		$this->assertNull( StorySyncProgress::from_meta_value( array( 'percent' => 'forty-five' ) ) );
	}
}
