<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryLocalLookup;
use Shorthand\Tests\WordPressTestCase;

final class StoryLocalLookupTest extends WordPressTestCase {

	public function test_returns_null_when_no_matching_post_exists(): void {
		tests_wp_set_posts_query_result( array() );

		$lookup = new StoryLocalLookup( 'tse_story' );

		$this->assertNull( $lookup->find_post_id_by_story_id( 'story-1' ) );
	}

	public function test_returns_post_id_when_a_matching_post_exists(): void {
		tests_wp_set_posts_query_result( array( '42' ) );

		$lookup = new StoryLocalLookup( 'tse_story' );

		$this->assertSame( 42, $lookup->find_post_id_by_story_id( 'story-1' ) );
	}

	public function test_queries_by_post_type_and_story_id_meta_in_any_status(): void {
		tests_wp_set_posts_query_result( array() );

		$lookup = new StoryLocalLookup( 'tse_story' );
		$lookup->find_post_id_by_story_id( 'story-1' );

		$queries = tests_wp_posts_queries();

		$this->assertCount( 1, $queries );
		$this->assertSame( 'tse_story', $queries[0]['post_type'] );
		$this->assertSame( 'any', $queries[0]['post_status'] );
		$this->assertSame( 'story_id', $queries[0]['meta_key'] );
		$this->assertSame( 'story-1', $queries[0]['meta_value'] );
		$this->assertSame( 1, $queries[0]['posts_per_page'] );
	}
}
