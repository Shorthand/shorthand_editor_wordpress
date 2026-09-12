<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\Files\WpUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * Reporting the uploads host's per-path write cap.
 *
 * The host permits a fixed number of modifications to one path and refuses
 * the next write. The status reaches the plugin only as text, in the error
 * `WP_Filesystem::copy()` leaves behind, so this covers both the match and
 * its failure.
 */
final class UploadsWriteCapTest extends WordPressTestCase {

	/**
	 * The error a refused write leaves, as the uploads host words it.
	 */
	private const REFUSAL_MESSAGE = 'Failed to upload file `/tmp/article.html` to `/wp-content/uploads/shorthand/1/abc/article.html` (response code: 405)';

	/** @var string */
	private $temp_root;

	/** @var \Shorthand\Services\Files\WpUploads */
	private $subject;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );

		$this->temp_root = sys_get_temp_dir() . '/sh_cap_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
		file_put_contents( $this->temp_root . '/article.html', 'article' );

		$this->subject = new WpUploads();
	}

	protected function tearDown(): void {
		tests_wp_set_copy_error( null );

		foreach ( array_diff( scandir( $this->temp_root ), array( '.', '..' ) ) as $entry ) {
			unlink( $this->temp_root . '/' . $entry );
		}

		rmdir( $this->temp_root );

		parent::tearDown();
	}

	public function test_a_refused_write_is_reported_as_an_error(): void {
		tests_wp_set_copy_error( 'upload_file-failed', self::REFUSAL_MESSAGE );

		$result = $this->write();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'pretty', $result->get_error_codes() );
	}

	/**
	 * The author reads this. It names no file, path, status code, or limit.
	 */
	public function test_the_author_facing_message_says_what_to_do(): void {
		tests_wp_set_copy_error( 'upload_file-failed', self::REFUSAL_MESSAGE );

		$result = $this->write();

		$this->assertSame(
			'This story can no longer be updated. Please contact Shorthand support.',
			$result->errors['pretty'][0]
		);
	}

	public function test_the_destination_path_stays_on_the_error_for_the_log(): void {
		tests_wp_set_copy_error( 'upload_file-failed', self::REFUSAL_MESSAGE );

		$result = $this->write();

		$this->assertStringContainsString( $this->dest_path(), $result->errors['file'][0] );
	}

	/**
	 * Every other refusal must read as it did before the match existed.
	 *
	 * @dataProvider other_failures
	 *
	 * @param string $code    Error code the host left behind.
	 * @param string $message Error message the host left behind.
	 */
	public function test_another_failure_is_not_named_as_the_write_cap( string $code, string $message ): void {
		tests_wp_set_copy_error( $code, $message );

		$this->assertFalse( $this->write() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function other_failures(): array {
		return array(
			'quota reached'     => array( 'upload_file-failed-quota_reached', 'Failed to upload file; file space quota has been exceeded.' ),
			'server error'      => array( 'upload_file-failed', 'Failed to upload file `/tmp/article.html` to `/wp-content/uploads/a` (response code: 500)' ),
			'wording change'    => array( 'upload_file-failed', 'Failed to upload file: method not allowed' ),
			'another operation' => array( 'get_file-failed', 'Failed to get file `/wp-content/uploads/a` (response code: 405)' ),
			'no error left'     => array( '', '' ),
		);
	}

	private function dest_path(): string {
		return 'vip://wp-content/uploads/shorthand/1/abc/article.html';
	}

	/**
	 * @return bool|\WP_Error
	 */
	private function write() {
		return $this->subject->write( $this->temp_root . '/article.html', $this->dest_path() );
	}
}
