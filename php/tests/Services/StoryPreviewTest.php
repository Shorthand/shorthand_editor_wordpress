<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryPreview;
use Shorthand\Tests\WordPressTestCase;

final class StoryPreviewTest extends WordPressTestCase {

	public function test_from_payload_defaults_missing_fields_and_exposes_content_version(): void {
		$preview = StoryPreview::from_payload(
			(object) array(
				'head' => '<title>Story</title>',
			),
			12
		);

		$this->assertInstanceOf( StoryPreview::class, $preview );
		$this->assertSame( '<title>Story</title>', $preview->get_head() );
		$this->assertSame( '', $preview->get_body() );
		$this->assertSame( 12, $preview->get_content_version() );
	}

	public function test_with_content_returns_a_new_preview_with_updated_head_and_body(): void {
		$preview = new StoryPreview( '<title>Old</title>', '<p>Old</p>', 8 );

		$updated_preview = $preview->with_content( '<title>New</title>', '<p>New</p>' );

		$this->assertSame( '<title>New</title>', $updated_preview->get_head() );
		$this->assertSame( '<p>New</p>', $updated_preview->get_body() );
		$this->assertSame( 8, $updated_preview->get_content_version() );
	}
}
