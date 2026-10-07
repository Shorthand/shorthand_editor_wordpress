<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services\Files;

use Shorthand\Services\Files\Archive;
use Shorthand\Tests\WordPressTestCase;
use ZipArchive;

/**
 * A story archive, read and then unpacked.
 */
final class ArchiveTest extends WordPressTestCase {

	/**
	 * Local directory the archive and its extract live in.
	 *
	 * @var string
	 */
	private $temp_root;

	protected function setUp(): void {
		parent::setUp();

		$this->temp_root = sys_get_temp_dir() . '/sh_archive_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
	}

	protected function tearDown(): void {
		foreach ( array( 'unpacked/article.html', 'unpacked', 'archive.zip' ) as $name ) {
			$path = $this->temp_root . '/' . $name;

			if ( is_dir( $path ) ) {
				rmdir( $path );
			} elseif ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		rmdir( $this->temp_root );

		parent::tearDown();
	}

	public function test_unpacking_closes_the_archive(): void {
		$archive = $this->open_archive();

		$this->assertTrue( $archive->unpack_to( $this->temp_root . '/unpacked' ) );
		$this->assertSame( 0, $this->getPrivateProperty( $archive, 'zip' )->numFiles, 'The archive is still open.' );
	}

	/**
	 * The staging directory is removed after a failed publish too, and a host
	 * may refuse to remove a file that is still open.
	 */
	public function test_a_failed_extract_still_closes_the_archive(): void {
		$archive = $this->open_archive();

		/* A file where the directory should be, so the extract cannot write. */
		touch( $this->temp_root . '/unpacked' );

		set_error_handler(
			static function (): bool {
				return true;
			},
			E_WARNING
		);

		try {
			$result = $archive->unpack_to( $this->temp_root . '/unpacked' );
		} finally {
			restore_error_handler();
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 0, $this->getPrivateProperty( $archive, 'zip' )->numFiles, 'The archive is still open.' );
	}

	private function open_archive(): Archive {
		$path = $this->temp_root . '/archive.zip';

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'article.html', '<h1>Story</h1>' );
		$zip->close();

		$archive = Archive::open( $path );
		$this->assertInstanceOf( Archive::class, $archive );

		return $archive;
	}
}
