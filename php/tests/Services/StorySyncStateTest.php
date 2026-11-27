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
			StorySyncProgress::from_meta_value(
				array(
					'percent'    => 60,
					'status'     => 'Saving story to WordPress',
					'updated_at' => 1700000000,
				)
			)
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
					'notice'     => null,
				),
				'liveVersion' => 9,
				'progress'    => array(
					'percent'    => 60.0,
					'status'     => 'Saving story to WordPress',
					'updated_at' => 1700000000,
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
					'notice'     => null,
				),
				'liveVersion' => 4,
			),
			$state->to_array()
		);
	}

	public function test_to_array_carries_a_title_shorthand_has_not_received(): void {
		$state = new StorySyncState( 4, null, null, 'Held title' );

		$this->assertSame( 'Held title', $state->to_array()['pendingTitle'] );
	}

	public function test_to_array_carries_the_notice_of_the_last_save(): void {
		$notice = array(
			array(
				'code'    => 'pretty',
				'message' => 'Could not check Shorthand for updates.',
				'data'    => null,
			),
		);

		$state = new StorySyncState( 4, null, null, null, $notice );

		$this->assertSame( $notice, $state->to_array()['errors']['notice'] );
	}
}
