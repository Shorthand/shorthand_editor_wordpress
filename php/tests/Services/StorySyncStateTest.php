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
				'warnings'    => array(
					'publishing' => null,
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
		$state = new StorySyncState( 4, null, null );

		$this->assertSame(
			array(
				'errors'      => array(
					'publishing' => null,
				),
				'warnings'    => array(
					'publishing' => null,
				),
				'liveVersion' => 4,
			),
			$state->to_array()
		);
	}

	public function test_to_array_includes_a_publishing_warning(): void {
		$warning = array(
			array(
				'message' => 'assets/AbC/x.jpg and assets/abc/x.jpg',
				'data'    => array( array( 'assets/AbC/x.jpg', 'assets/abc/x.jpg' ) ),
				'code'    => 'collision',
			),
		);

		$state = new StorySyncState( 4, null, null, null, $warning );

		$this->assertSame( $warning, $state->to_array()['warnings']['publishing'] );
	}

	public function test_to_array_carries_a_title_shorthand_has_not_received(): void {
		$state = new StorySyncState( 4, null, null, 'Held title' );

		$this->assertSame( 'Held title', $state->to_array()['pendingTitle'] );
	}
}
