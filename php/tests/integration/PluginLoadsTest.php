<?php

class PluginLoadsTest extends WP_UnitTestCase {

	public function test_story_post_type_is_registered(): void {
		$this->assertTrue( post_type_exists( 'tse_story' ) );
	}

	public function test_story_post_type_is_public(): void {
		$story = get_post_type_object( 'tse_story' );

		$this->assertTrue( $story->public );
		$this->assertTrue( $story->publicly_queryable );
		$this->assertTrue( $story->show_in_rest );
	}

	public function test_story_can_be_created(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'tse_story' ) );

		$this->assertSame( 'tse_story', get_post_type( $post_id ) );
	}
}
