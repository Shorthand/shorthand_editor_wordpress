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
	 * Credentials arrive with a new request, never part way through one, so a
	 * second boot would only print the credentials form again.
	 */
	public function test_a_failed_boot_is_not_retried(): void {
		$this->assertNull( FileSystem::boot() );
		$this->assertNull( FileSystem::boot() );

		$this->assertSame( 1, tests_wp_credential_requests() );
	}

	public function test_a_read_fails(): void {
		$this->assertFalse( $this->subject->read_into( '/uploads/a.part', '/tmp/archive.zip' ) );
	}

	public function test_a_delete_fails(): void {
		$this->assertFalse( $this->subject->delete( '/uploads/a.part' ) );
	}

	/**
	 * A download is recorded only once its directory is made, so a directory
	 * made here would record a pull nothing could write or read.
	 */
	public function test_a_make_dir_fails_and_makes_nothing(): void {
		$path = sys_get_temp_dir() . '/sh_make_dir_' . getmypid() . '_' . uniqid() . '/7';

		$this->assertFalse( $this->subject->make_dir( $path ) );
		$this->assertDirectoryDoesNotExist( dirname( $path ) );
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
