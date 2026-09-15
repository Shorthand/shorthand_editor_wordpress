<?php

class PluginLoadsTest extends WP_UnitTestCase {

	public function test_story_post_type_is_registered(): void {
		$this->assertTrue( post_type_exists( 'tse_story' ) );
	}

	public function test_story_post_type_is_not_public_by_default(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'tse_story' ) );

		$this->assertSame( 'tse_story', get_post_type( $post_id ) );
	}
}
