<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services\Files;

use Shorthand\Services\Files\FileSystem;
use Shorthand\Services\Files\WpUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * Uploads on a host where `WP_Filesystem` will not boot.
 *
 * Booting asks for credentials and can come back with nothing. Every call
 * must report that as a failure the publish can carry, not reach into an
 * instance that is not there.
 */
final class WpUploadsTest extends WordPressTestCase {

	/** @var \Shorthand\Services\Files\WpUploads */
	private $subject;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_filesystem_available( false );
		$this->forget_booted_file_system();

		$this->subject = new WpUploads();
	}

	protected function tearDown(): void {
		tests_wp_set_filesystem_available( true );
		$this->forget_booted_file_system();

		parent::tearDown();
	}

	public function test_the_file_system_is_unavailable(): void {
		$this->assertNull( FileSystem::boot() );
	}

	/**
	 * The publish reports this and stops; it is not a silent skip.
	 */
	public function test_a_write_reports_the_file_system_it_could_not_reach(): void {
		$result = $this->subject->write( '/tmp/article.html', '/uploads/shorthand/7/aBc123/article.html' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '/uploads/shorthand/7/aBc123/article.html', $result->get_error_data( 'file' ) );
	}

	/**
	 * Only a successful boot is remembered, so a host that asks for
	 * credentials once is not written off for the rest of the request.
	 */
	public function test_a_failed_boot_is_retried(): void {
		$this->assertNull( FileSystem::boot() );

		tests_wp_set_filesystem_available( true );

		$this->assertInstanceOf( \WP_Filesystem_Base::class, FileSystem::boot() );
	}

	public function test_a_read_fails(): void {
		$this->assertFalse( $this->subject->read_into( '/uploads/a.part', '/tmp/archive.zip' ) );
	}

	public function test_a_delete_fails(): void {
		$this->assertFalse( $this->subject->delete( '/uploads/a.part' ) );
	}

	/**
	 * A boot is remembered for the request, and one test is one request.
	 */
	private function forget_booted_file_system(): void {
		unset( $GLOBALS['wp_filesystem'] );

		$booted = new \ReflectionProperty( FileSystem::class, 'booted' );

		if ( method_exists( $booted, 'setAccessible' ) ) {
			$booted->setAccessible( true );
		}

		$booted->setValue( null, false );
	}
}
