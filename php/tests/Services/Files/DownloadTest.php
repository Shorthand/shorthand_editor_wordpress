<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services\Files;

use Shorthand\Services\Files\Bundle;
use Shorthand\Services\Files\BundleStore;
use Shorthand\Services\Files\Download;
use Shorthand\Tests\Support\FakeUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * Where the chunks of one story archive are written, and how they are removed.
 */
final class DownloadTest extends WordPressTestCase {

	/** @var \Shorthand\Tests\Support\FakeUploads */
	private $uploads;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( '/uploads', 'https://example.test/uploads' );

		$this->uploads = new FakeUploads();
	}

	/**
	 * Chunks are named beside the bundle, not inside a directory of their own:
	 * an empty directory cannot be removed on every host.
	 */
	public function test_chunks_are_named_beside_the_bundle(): void {
		$download = $this->open();

		$this->assertSame( '/uploads/shorthand/7/aBc123_44444_0.part', $download->chunk_path( 0 ) );
		$this->assertSame( '/uploads/shorthand/7/aBc123_44444_3.part', $download->chunk_path( 3 ) );
	}

	public function test_the_chunk_paths_are_listed_in_download_order(): void {
		$this->assertSame(
			array(
				'/uploads/shorthand/7/aBc123_44444_0.part',
				'/uploads/shorthand/7/aBc123_44444_1.part',
			),
			$this->open()->chunk_paths( 2 )
		);
	}

	public function test_starting_a_download_creates_only_the_permanent_parent(): void {
		$this->open()->start();

		$this->assertSame( 1, $this->uploads->make_dir_calls() );
	}

	public function test_discarding_a_download_removes_every_chunk_it_named(): void {
		$download = $this->open();

		$this->uploads->put( $download->chunk_path( 0 ), 'first' );
		$this->uploads->put( $download->chunk_path( 1 ), 'second' );

		$download->discard( 2 );

		$this->assertSame( array(), $this->uploads->objects() );
	}

	/**
	 * A download queued before the chunk rename wrote into a directory beside
	 * the bundle. The path is derived, never taken from the stored task.
	 */
	public function test_discarding_a_legacy_download_removes_chunks_at_the_old_naming(): void {
		$this->uploads->put( '/uploads/shorthand/7/aBc123_44444/file-0.part', 'first' );
		$this->uploads->put( '/uploads/shorthand/7/aBc123_44444/file-1.part', 'second' );

		$this->open()->discard_legacy( 2 );

		$this->assertSame( array(), $this->uploads->objects() );
	}

	/**
	 * The nonce is interpolated into the documents directory of a publish, so
	 * one that is not a path segment is refused rather than escaped.
	 */
	public function test_a_nonce_that_is_not_a_path_segment_has_no_segment(): void {
		$this->assertSame( '44444', $this->open()->segment() );
		$this->assertSame( '', $this->open( '../../etc' )->segment() );
	}

	private function open( string $nonce = '44444' ): Download {
		$bundle = ( new BundleStore( $this->uploads ) )->open( 7, 'aBc123' );

		$this->assertInstanceOf( Bundle::class, $bundle );

		return $bundle->download( $nonce );
	}
}
