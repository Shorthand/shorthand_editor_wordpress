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

	public function test_from_meta_value_rejects_invalid_shapes(): void {
		$this->assertNull( StorySyncProgress::from_meta_value( array( 'status' => 'Saving story to WordPress' ) ) );
		$this->assertNull( StorySyncProgress::from_meta_value( array( 'percent' => 'forty-five' ) ) );
	}
}
