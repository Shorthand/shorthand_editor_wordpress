<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\Files\FileSystem;
use Shorthand\Services\Files\WpUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * What the file system abstraction is not allowed to do.
 *
 * The uploads directory is used the same way on every host, so nothing here
 * asks which host it is on and nothing enumerates. See
 * `docs/services/file-system.md`.
 */
final class FilesTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/uploads' );
	}

	/**
	 * Booting `WP_Filesystem` is an admin-weight operation, and the uploads
	 * service is constructed on every admin request. It waits for the first call.
	 */
	public function test_constructing_the_uploads_service_does_not_boot_wp_filesystem(): void {
		$this->forget_filesystem_boot();

		new WpUploads();

		$this->assertArrayNotHasKey( 'wp_filesystem', $GLOBALS, 'Constructing the service booted WP_Filesystem.' );

		( new WpUploads() )->delete( $this->temp_path() );

		$this->assertArrayHasKey( 'wp_filesystem', $GLOBALS, 'A call did not boot WP_Filesystem.' );
	}

	public function test_the_boot_is_done_once_and_returns_the_same_filesystem(): void {
		$this->forget_filesystem_boot();

		$this->assertSame( FileSystem::boot(), FileSystem::boot() );
	}

	/**
	 * The host is never inferred from a vendor constant or a named plugin, and
	 * no vendor is supported by name.
	 */
	public function test_no_service_names_a_vendor(): void {
		foreach ( $this->sources() as $source ) {
			$this->assertDoesNotMatchRegularExpression(
				'/VIP_GO_APP_ENVIRONMENT|wpcomvip|WPCOM_VIP|Automattic\\\\|stateless|amazonS3|as3cf/i',
				(string) file_get_contents( $source ),
				basename( $source ) . ' names a vendor.'
			);
		}
	}

	/**
	 * Uploads cannot be listed on every host, so nothing may try.
	 */
	public function test_nothing_enumerates_or_removes_a_directory(): void {
		foreach ( $this->sources() as $source ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b(scandir|glob|opendir|readdir|list_files|dirlist|rmdir)\s*\(/i',
				$this->code_of( $source ),
				basename( $source ) . ' enumerates or removes a directory.'
			);
		}
	}

	/**
	 * A source file with its comments dropped, so documentation of a rule does
	 * not read as a breach of it.
	 *
	 * @param string $source Path of the file to read.
	 */
	private function code_of( string $source ): string {
		$code = '';

		foreach ( token_get_all( (string) file_get_contents( $source ) ) as $token ) {
			if ( ! is_array( $token ) ) {
				$code .= $token;
				continue;
			}

			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}

			$code .= $token[1];
		}

		return $code;
	}

	/**
	 * Every service source file, including the file system abstraction.
	 *
	 * @return string[]
	 */
	private function sources(): array {
		return array_merge(
			(array) glob( __DIR__ . '/../../src/lib/Services/*.php' ),
			(array) glob( __DIR__ . '/../../src/lib/Services/Files/*.php' )
		);
	}

	/**
	 * Returns the boot to the state of a fresh request.
	 */
	private function forget_filesystem_boot(): void {
		$forget = \Closure::bind(
			static function (): void {
				FileSystem::$booted = false;
			},
			null,
			FileSystem::class
		);

		$forget();

		unset( $GLOBALS['wp_filesystem'] );
	}

	/**
	 * A path under the system temp directory that need not exist.
	 */
	private function temp_path(): string {
		return sys_get_temp_dir() . '/sh_absent_' . getmypid();
	}
}
