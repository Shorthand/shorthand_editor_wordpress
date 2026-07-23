<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Tests\WordPressTestCase;

final class PostAPITest extends WordPressTestCase {

	private function make_post_api( Shorthand $shorthand ): PostAPI {
		return new PostAPI(
			$shorthand,
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class )
		);
	}

	public function test_connect_story_creates_a_draft_post_by_default(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn(
			array(
				'meta' => array(
					'title'       => 'My Story',
					'description' => 'A description',
				),
			)
		);
		$shorthand->expects( $this->once() )->method( 'set_story_external_id' )->with( 'story-1', 123 )->willReturn( true );

		tests_wp_set_insert_post_result( 123 );
		tests_wp_set_post( 123, (object) array( 'ID' => 123 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post     = $post_api->connect_story( 'story-1', null );

		$this->assertSame( 123, $post->ID );

		$inserted = tests_wp_inserted_posts();
		$this->assertCount( 1, $inserted );
		$this->assertSame( 'draft', $inserted[0]['post_status'] );
		$this->assertSame( 'My Story', $inserted[0]['post_title'] );
		$this->assertSame( 'tse_story', $inserted[0]['post_type'] );
	}

	public function test_connect_story_links_the_new_post_to_the_story_via_meta(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->willReturn( array( 'meta' => array( 'title' => 'My Story' ) ) );
		$shorthand->method( 'set_story_external_id' )->willReturn( true );

		tests_wp_set_insert_post_result( 123 );
		tests_wp_set_post( 123, (object) array( 'ID' => 123 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post_api->connect_story( 'story-1', null );

		$updated_meta = tests_wp_updated_post_meta();
		$this->assertCount( 1, $updated_meta );
		$this->assertSame( 123, $updated_meta[0]['post_id'] );
		$this->assertSame( 'story_id', $updated_meta[0]['meta_key'] );
		$this->assertSame( 'story-1', $updated_meta[0]['meta_value'] );
	}

	public function test_connect_story_respects_an_explicit_post_status(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->method( 'get_story_settings' )->willReturn( array( 'meta' => array( 'title' => 'My Story' ) ) );
		$shorthand->method( 'set_story_external_id' )->willReturn( true );

		tests_wp_set_insert_post_result( 55 );
		tests_wp_set_post( 55, (object) array( 'ID' => 55 ) );

		$post_api = $this->make_post_api( $shorthand );
		$post_api->connect_story( 'story-1', null, 'draft' );

		$inserted = tests_wp_inserted_posts();
		$this->assertSame( 'draft', $inserted[0]['post_status'] );
	}
}
