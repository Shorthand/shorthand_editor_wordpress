<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryListCursor;
use Shorthand\Tests\WordPressTestCase;

final class StoryListCursorTest extends WordPressTestCase {

	public function test_encode_produces_a_decodable_base64_json_cursor(): void {
		$cursor  = StoryListCursor::encode( '2024-01-02T03:04:05.000Z', 'story-9' );
		$decoded = json_decode( base64_decode( $cursor ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertSame( '2024-01-02T03:04:05.000Z', $decoded['updatedAt'] );
		$this->assertSame( 'story-9', $decoded['id'] );
	}

	public function test_next_cursor_is_null_when_page_is_shorter_than_limit(): void {
		$stories = array(
			array(
				'id'        => 's1',
				'updatedAt' => '2024-01-01T00:00:00.000Z',
			),
		);

		$this->assertNull( StoryListCursor::next_cursor( $stories, 20 ) );
	}

	public function test_next_cursor_is_null_for_an_empty_page(): void {
		$this->assertNull( StoryListCursor::next_cursor( array(), 20 ) );
	}

	public function test_next_cursor_derives_from_the_last_item_of_a_full_page(): void {
		$stories = array(
			array(
				'id'        => 's1',
				'updatedAt' => '2024-01-02T00:00:00.000Z',
			),
			array(
				'id'        => 's2',
				'updatedAt' => '2024-01-01T00:00:00.000Z',
			),
		);

		$cursor = StoryListCursor::next_cursor( $stories, 2 );

		$this->assertNotNull( $cursor );
		$decoded = json_decode( base64_decode( $cursor ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( 's2', $decoded['id'] );
		$this->assertSame( '2024-01-01T00:00:00.000Z', $decoded['updatedAt'] );
	}

	public function test_next_cursor_is_null_when_last_item_is_missing_required_fields(): void {
		$stories = array(
			array( 'id' => 's1' ),
			array( 'id' => 's2' ),
		);

		$this->assertNull( StoryListCursor::next_cursor( $stories, 2 ) );
	}
}
