<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\Files\Manifest;
use Shorthand\Tests\WordPressTestCase;
use ZipArchive;

/**
 * Reading a bundle manifest out of a story archive index.
 */
final class ManifestTest extends WordPressTestCase {

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
		$manifest = Manifest::from_archive(
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

		$this->assertSame( array( 'assets/theme.css' ), array_keys( Manifest::from_archive( $zip ) ) );

		$zip->close();
	}

	public function test_entries_are_ordered_by_name(): void {
		$manifest = Manifest::from_archive(
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

		$this->assertSame( array( 'assets/media/old.jpg' ), array_keys( Manifest::removed( $stored, $current ) ) );
	}

	/**
	 * The host folds case, so deleting the old name would delete the file the
	 * new name was just written to.
	 */
	public function test_a_name_that_only_changed_case_is_kept(): void {
		$stored  = array(
			'assets/media/Photo.JPG' => array(
				'size' => 6,
				'crc'  => 2,
			),
			'assets/media/gone.jpg'  => array(
				'size' => 4,
				'crc'  => 5,
			),
		);
		$current = array(
			'assets/media/photo.jpg' => array(
				'size' => 6,
				'crc'  => 2,
			),
		);

		$this->assertSame( array( 'assets/media/gone.jpg' ), array_keys( Manifest::removed( $stored, $current ) ) );
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
		$this->assertSame( array(), Manifest::from_meta( $value ) );
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

	/**
	 * Nothing the plugin stores has an unreadable entry, so one means the meta
	 * was written by something else. The file it names survives every prune
	 * and delete, both of which work from the manifest alone.
	 */
	public function test_an_unreadable_entry_is_reported(): void {
		$manifest = Manifest::from_meta(
			array(
				'article.html'     => array(
					'size' => 7,
					'crc'  => 1,
				),
				'assets/theme.css' => array( 'size' => 6 ),
			)
		);

		$this->assertSame( array( 'article.html' ), array_keys( $manifest ) );

		$reports = tests_wp_doing_it_wrong();

		$this->assertCount( 1, $reports );
		$this->assertStringContainsString( 'assets/theme.css', $reports[0]['message'] );
	}

	/**
	 * A first publish after upgrading reads a manifest that was never written.
	 */
	public function test_an_unusable_meta_value_is_not_reported(): void {
		Manifest::from_meta( '' );

		$this->assertSame( array(), tests_wp_doing_it_wrong() );
	}

	public function test_a_stored_manifest_reads_back_unchanged(): void {
		$manifest = array(
			'article.html' => array(
				'size' => 7,
				'crc'  => 1,
			),
		);

		$this->assertSame( $manifest, Manifest::from_meta( $manifest ) );
	}

	public function test_relocating_the_documents_moves_only_the_documents(): void {
		$manifest = Manifest::relocate_documents(
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
		$manifest = Manifest::relocate_documents(
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
	 * Entry names become path segments of the bundle, the same way a story ID
	 * and a nonce do, and unlike those two they arrive from outside. PLA-2720.
	 *
	 * @dataProvider escaping_entry_names
	 *
	 * @param string $name Archive entry name.
	 */
	public function test_an_entry_that_escapes_the_bundle_is_refused( string $name ): void {
		$zip = $this->open_archive( array( $name => 'payload' ) );

		$result = Manifest::from_archive( $zip );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $name, $result->get_error_data( 'file' ) );

		$zip->close();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function escaping_entry_names(): array {
		return array(
			'parent directory'   => array( '../../escaped.txt' ),
			'parent mid path'    => array( 'x/../../escaped.txt' ),
			'absolute path'      => array( '/etc/passwd' ),
			'backslash'          => array( 'abc\\def' ),
			'windows drive'      => array( 'C:/Windows/x' ),
		);
	}

	/**
	 * Dots and slashes are ordinary in a story's asset names.
	 *
	 * @dataProvider ordinary_entry_names
	 *
	 * @param string $name Archive entry name.
	 */
	public function test_an_ordinary_entry_name_is_kept( string $name ): void {
		$manifest = Manifest::from_archive( $this->open_archive( array( $name => 'payload' ) ) );

		$this->assertSame( array( $name ), array_keys( $manifest ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function ordinary_entry_names(): array {
		return array(
			'nested'         => array( 'assets/media/photo.jpg' ),
			'leading dot'    => array( 'assets/.htaccess' ),
			'dot segment'    => array( 'assets/./theme.css' ),
			'double dot name' => array( 'assets/..photo.jpg' ),
		);
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
