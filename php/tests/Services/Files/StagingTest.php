<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services\Files;

use Shorthand\Services\Files\Staging;
use Shorthand\Tests\Support\FakeUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * The local scratch directory one publish owns.
 */
final class StagingTest extends WordPressTestCase {

	/**
	 * Discarding needs `WP_Filesystem`, and a locked-down host cannot boot it.
	 * The directory then stays where it is, which the caller is told.
	 */
	public function test_discarding_answers_false_when_the_file_system_cannot_boot(): void {
		$staging = Staging::open( new FakeUploads(), 'sh_staging_test_' );

		$this->forgetFileSystemBoot();
		\tests_wp_set_filesystem_available( false );

		$this->assertFalse( $staging->discard() );

		\tests_wp_set_filesystem_available( true );
		$this->forgetFileSystemBoot();

		$this->assertTrue( $staging->discard(), 'The staging directory was not removed once the boot succeeded.' );
	}
}
