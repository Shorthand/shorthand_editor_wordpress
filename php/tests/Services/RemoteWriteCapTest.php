<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\RemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;

/**
 * Reporting the uploads host's per-path write cap.
 *
 * The host permits a fixed number of modifications to one path and answers
 * the next write with HTTP 405. That status reaches the plugin only as text
 * in a PHP warning, so this covers both the match and its failure.
 */
final class RemoteWriteCapTest extends WordPressTestCase {

	/** @var string */
	private $temp_root;

	/** @var \Shorthand\Services\RemoteFileSystem */
	private $subject;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/wp-content/uploads' );

		$this->temp_root = sys_get_temp_dir() . '/sh_cap_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
		file_put_contents( $this->temp_root . '/article.html', 'article' );

		$this->subject = new RemoteFileSystem();
	}

	protected function tearDown(): void {
		tests_wp_set_copy_warning( null );

		if ( is_dir( $this->bundle_dir() ) ) {
			rmdir( $this->bundle_dir() );
		}

		foreach ( array_diff( scandir( $this->temp_root ), array( '.', '..' ) ) as $entry ) {
			unlink( $this->temp_root . '/' . $entry );
		}

		rmdir( $this->temp_root );

		parent::tearDown();
	}

	public function test_a_refused_write_is_reported_as_an_error(): void {
		tests_wp_set_copy_warning( 'upload_file-failed (response code: 405)' );

		$result = $this->copy_tree();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'pretty', $result->get_error_codes() );
	}

	/**
	 * The author reads this. It names no file, path, status code, or limit.
	 */
	public function test_the_author_facing_message_says_what_to_do(): void {
		tests_wp_set_copy_warning( 'upload_file-failed (response code: 405)' );

		$result = $this->copy_tree();

		$this->assertSame(
			'This story can no longer be updated. Please contact Shorthand support.',
			$result->errors['pretty'][0]
		);
	}

	public function test_the_bundle_path_stays_on_the_error_for_the_log(): void {
		tests_wp_set_copy_warning( 'upload_file-failed (response code: 405)' );

		$result = $this->copy_tree();

		$this->assertStringContainsString( $this->bundle_dir() . '/article.html', $result->errors['file'][0] );
	}

	/**
	 * Every other refusal must read as it did before the match existed.
	 *
	 * @dataProvider other_warnings
	 *
	 * @param string $warning Warning text raised by the failed write.
	 */
	public function test_another_failure_is_not_named_as_the_write_cap( string $warning ): void {
		tests_wp_set_copy_warning( $warning );

		$result = $this->copy_tree();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotContains( 'pretty', $result->get_error_codes() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function other_warnings(): array {
		return array(
			'quota reached'  => array( 'upload_file-failed-quota_reached' ),
			'server error'   => array( 'upload_file-failed (response code: 500)' ),
			'wording change' => array( 'upload_file-failed: method not allowed' ),
		);
	}

	private function bundle_dir(): string {
		return $this->temp_root . '/bundle';
	}

	/**
	 * @return array|\WP_Error
	 */
	private function copy_tree() {
		return $this->subject->copy_tree(
			$this->temp_root,
			$this->bundle_dir(),
			array(
				'article.html' => array(
					'size' => 7,
					'crc'  => crc32( 'article' ),
				),
			),
			null
		);
	}
}
