<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StorySyncProgress;
use Shorthand\Services\StorySyncState;
use Shorthand\Tests\WordPressTestCase;

final class StorySyncStateTest extends WordPressTestCase {

	public function test_to_array_includes_progress_and_errors_when_present(): void {
		$state = new StorySyncState(
			9,
			array(
				array(
					'code'    => 'story',
					'message' => 'Publishing failed.',
					'data'    => 500,
				),
			),
			new StorySyncProgress( 60, 'Saving story to WordPress' )
		);

		$this->assertSame(
			array(
				'errors'      => array(
					'publishing' => array(
						array(
							'code'    => 'story',
							'message' => 'Publishing failed.',
							'data'    => 500,
						),
					),
				),
				'liveVersion' => 9,
				'progress'    => array(
					'percent' => 60.0,
					'status'  => 'Saving story to WordPress',
				),
			),
			$state->to_array()
		);
	}

	public function test_to_array_omits_progress_when_none_is_available(): void {
		$state = new StorySyncState( 4 );

		$this->assertSame(
			array(
				'errors'      => array(
					'publishing' => null,
				),
				'liveVersion' => 4,
			),
			$state->to_array()
		);
	}
}
