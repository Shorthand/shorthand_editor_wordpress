<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services\Files;

use Shorthand\Services\Files\Bundle;
use Shorthand\Services\Files\BundleStore;
use Shorthand\Tests\Support\FakeUploads;
use Shorthand\Tests\WordPressTestCase;

/**
 * Where a story's files live, and who is allowed to open the directory.
 */
final class BundleTest extends WordPressTestCase {

	/** @var \Shorthand\Tests\Support\FakeUploads */
	private $uploads;

	protected function setUp(): void {
		parent::setUp();

		tests_wp_set_upload_dir( '/uploads', 'https://example.test/uploads' );

		$this->uploads = new FakeUploads();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function path_shaped_story_ids(): array {
		return array(
			'parent directory' => array( '../../wp-content' ),
			'absolute path'    => array( '/etc/passwd' ),
			'backslash'        => array( 'abc\\def' ),
			'null byte'        => array( "abc\0def" ),
			'empty'            => array( '' ),
		);
	}

	/**
	 * @dataProvider path_shaped_story_ids
	 */
	public function test_a_story_id_that_is_not_a_path_segment_opens_no_bundle( string $story_id ): void {
		$this->assertNull( $this->store()->open( 7, $story_id ) );
	}

	public function test_the_bundle_path_and_url_keep_the_case_of_the_story_id(): void {
		$bundle = $this->open();

		$this->assertSame( '/uploads/shorthand/7/aBc123', $bundle->path() );
		$this->assertSame( 'https://example.test/uploads/shorthand/7/aBc123', $bundle->url() );
	}

	/**
	 * A sidecar plugin serving the files from elsewhere replaces the URL.
	 */
	public function test_the_bundle_url_passes_through_a_filter(): void {
		$this->open()->url();

		$this->assertCount( 1, tests_wp_get_filter_args( 'theshed_get_story_url' ) );
	}

	private function store(): BundleStore {
		return new BundleStore( $this->uploads );
	}

	private function open(): Bundle {
		$bundle = $this->store()->open( 7, 'aBc123' );

		$this->assertInstanceOf( Bundle::class, $bundle );

		return $bundle;
	}
}
