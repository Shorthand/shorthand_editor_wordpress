<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\FileSystem;
use Shorthand\Services\LocalFileSystem;
use Shorthand\Services\RemoteFileSystem;
use Shorthand\Tests\WordPressTestCase;

/**
 * The uploads host is chosen from the shape of the uploads path alone.
 *
 * See `docs/services/file-system.md`.
 */
final class FileSystemTest extends WordPressTestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function remote_basedirs(): array {
		return array(
			'WordPress VIP'  => array( 'vip://wp-content/uploads' ),
			'Google Storage' => array( 'gs://example-bucket/uploads' ),
			'S3'             => array( 's3://example-bucket/uploads' ),
			'uppercase'      => array( 'VIP://wp-content/uploads' ),
			'plus in scheme' => array( 'a+b://bucket/uploads' ),
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function local_basedirs(): array {
		return array(
			'absolute path'     => array( '/var/www/html/wp-content/uploads' ),
			'relative path'     => array( 'wp-content/uploads' ),
			'colon in a path'   => array( '/var/www/my:site/uploads' ),
			'Windows drive'     => array( 'C:\\inetpub\\uploads' ),
			'scheme-like start' => array( '/vip://wp-content/uploads' ),
			'empty'             => array( '' ),
		);
	}

	/**
	 * @dataProvider remote_basedirs
	 */
	public function test_a_basedir_naming_a_stream_wrapper_is_remote( string $basedir ): void {
		tests_wp_set_upload_dir( $basedir, 'https://example.test/uploads' );

		$this->assertTrue( FileSystem::is_remote_uploads() );
	}

	/**
	 * @dataProvider local_basedirs
	 */
	public function test_a_basedir_naming_a_directory_is_local( string $basedir ): void {
		tests_wp_set_upload_dir( $basedir, 'https://example.test/uploads' );

		$this->assertFalse( FileSystem::is_remote_uploads() );
	}

	public function test_create_returns_the_remote_implementation_for_a_stream_wrapper(): void {
		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/uploads' );

		$this->assertInstanceOf( RemoteFileSystem::class, FileSystem::create() );
	}

	public function test_create_returns_the_local_implementation_for_a_directory(): void {
		tests_wp_set_upload_dir( '/var/www/html/wp-content/uploads', 'https://example.test/uploads' );

		$this->assertInstanceOf( LocalFileSystem::class, FileSystem::create() );
	}

	/**
	 * Booting `WP_Filesystem` is an admin-weight operation, and a service is
	 * constructed on every admin request. It waits for the first write.
	 */
	public function test_constructing_a_service_does_not_boot_wp_filesystem(): void {
		$this->forget_filesystem_boot();

		new LocalFileSystem();

		$this->assertArrayNotHasKey( 'wp_filesystem', $GLOBALS, 'Constructing a service booted WP_Filesystem.' );

		( new LocalFileSystem() )->delete_file( $this->temp_path() );

		$this->assertArrayHasKey( 'wp_filesystem', $GLOBALS, 'Writing did not boot WP_Filesystem.' );
	}

	/**
	 * Returns the boot to the state of a fresh request.
	 */
	private function forget_filesystem_boot(): void {
		$forget = \Closure::bind(
			static function (): void {
				FileSystem::$has_init_fs = false;
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

	/**
	 * The host is never inferred from a vendor constant or a named plugin.
	 */
	public function test_no_service_names_a_vendor(): void {
		$sources = glob( __DIR__ . '/../../src/lib/Services/*.php' );

		foreach ( $sources as $source ) {
			$this->assertDoesNotMatchRegularExpression(
				'/VIP_GO_APP_ENVIRONMENT|wpcomvip|WPCOM_VIP|Automattic\\\\|stateless|amazonS3|as3cf/i',
				(string) file_get_contents( $source ),
				basename( $source ) . ' names a vendor.'
			);
		}
	}
}
