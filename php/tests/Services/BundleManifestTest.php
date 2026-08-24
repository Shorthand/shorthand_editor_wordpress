<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\BundleManifest;
use Shorthand\Tests\WordPressTestCase;
use ZipArchive;

/**
 * Reading a bundle manifest out of a story archive index.
 */
final class BundleManifestTest extends WordPressTestCase {

	/** @var string */
	private $temp_root;

	protected function setUp(): void {
		parent::setUp();

		$this->temp_root = sys_get_temp_dir() . '/sh_manifest_' . getmypid() . '_' . uniqid();
		mkdir( $this->temp_root, 0777, true );
	}

	protected function tearDown(): void {
		foreach ( array_diff( scandir( $this->temp_root ), array( '.', '..' ) ) as $entry ) {
			unlink( $this->temp_root . '/' . $entry );
		}

		rmdir( $this->temp_root );

		parent::tearDown();
	}

	public function test_the_manifest_records_the_size_and_crc_of_every_entry(): void {
		$manifest = BundleManifest::from_archive(
			$this->open_archive(
				array(
					'article.html'           => 'article',
					'assets/media/photo.jpg' => 'binary',
				)
			)
		);

		$this->assertSame(
			array(
				'article.html'           => array(
					'size' => 7,
					'crc'  => crc32( 'article' ),
				),
				'assets/media/photo.jpg' => array(
					'size' => 6,
					'crc'  => crc32( 'binary' ),
				),
			),
			$manifest
		);
	}

	/**
	 * Nothing extracts the archive, so directory entries name nothing to copy.
	 */
	public function test_directory_entries_are_left_out(): void {
		$zip = new ZipArchive();
		$zip->open( $this->temp_root . '/archive.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addEmptyDir( 'assets' );
		$zip->addFromString( 'assets/theme.css', 'body{}' );
		$zip->close();

		$zip = new ZipArchive();
		$zip->open( $this->temp_root . '/archive.zip' );

		$this->assertSame( array( 'assets/theme.css' ), array_keys( BundleManifest::from_archive( $zip ) ) );

		$zip->close();
	}

	public function test_entries_are_ordered_by_name(): void {
		$manifest = BundleManifest::from_archive(
			$this->open_archive(
				array(
					'head.html'        => 'head',
					'article.html'     => 'article',
					'assets/theme.css' => 'body{}',
				)
			)
		);

		$this->assertSame( array( 'article.html', 'assets/theme.css', 'head.html' ), array_keys( $manifest ) );
	}

	public function test_removed_names_the_entries_that_left_the_archive(): void {
		$stored  = array(
			'article.html'         => array(
				'size' => 7,
				'crc'  => 1,
			),
			'assets/media/old.jpg' => array(
				'size' => 6,
				'crc'  => 2,
			),
		);
		$current = array(
			'article.html' => array(
				'size' => 15,
				'crc'  => 3,
			),
		);

		$this->assertSame( array( 'assets/media/old.jpg' ), array_keys( BundleManifest::removed( $stored, $current ) ) );
	}

	/**
	 * An absent manifest means copy everything, which is correct on the first
	 * publish after upgrading.
	 *
	 * @dataProvider unusable_meta_values
	 *
	 * @param mixed $value Value read back from post meta.
	 */
	public function test_an_unusable_meta_value_reads_as_an_empty_manifest( $value ): void {
		$this->assertSame( array(), BundleManifest::from_meta( $value ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusable_meta_values(): array {
		return array(
			'never written' => array( '' ),
			'false'         => array( false ),
			'null'          => array( null ),
			'a string'      => array( 'article.html' ),
		);
	}

	public function test_a_stored_manifest_reads_back_unchanged(): void {
		$manifest = array(
			'article.html' => array(
				'size' => 7,
				'crc'  => 1,
			),
		);

		$this->assertSame( $manifest, BundleManifest::from_meta( $manifest ) );
	}

	public function test_relocating_the_documents_moves_only_the_documents(): void {
		$manifest = BundleManifest::relocate_documents(
			array(
				'article.html'           => array(
					'size' => 7,
					'crc'  => 1,
				),
				'assets/media/photo.jpg' => array(
					'size' => 6,
					'crc'  => 2,
				),
				'head.html'              => array(
					'size' => 4,
					'crc'  => 3,
				),
			),
			'docs/pull1'
		);

		$this->assertSame(
			array( 'assets/media/photo.jpg', 'docs/pull1/article.html', 'docs/pull1/head.html' ),
			array_keys( $manifest )
		);
		$this->assertSame( 7, $manifest['docs/pull1/article.html']['size'] );
		$this->assertSame( 'article.html', $manifest['docs/pull1/article.html']['from'] );
		$this->assertArrayNotHasKey( 'from', $manifest['assets/media/photo.jpg'] );
	}

	/**
	 * A story with no head material still publishes.
	 */
	public function test_relocating_the_documents_tolerates_an_absent_one(): void {
		$manifest = BundleManifest::relocate_documents(
			array(
				'article.html' => array(
					'size' => 7,
					'crc'  => 1,
				),
			),
			'docs/pull1'
		);

		$this->assertSame( array( 'docs/pull1/article.html' ), array_keys( $manifest ) );
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function open_archive( array $entries ): ZipArchive {
		$path = $this->temp_root . '/archive.zip';

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}

		$zip->close();

		$zip = new ZipArchive();
		$zip->open( $path );

		return $zip;
	}
}
