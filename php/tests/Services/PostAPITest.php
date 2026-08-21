<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryContentTransformer;
use Shorthand\Services\StoryTextExtractor;
use Shorthand\Tests\WordPressTestCase;

final class PostAPITest extends WordPressTestCase {

	private const STORY = '<div class="Theme-Story">
		<section class="Theme-Section Theme-TitleSection">
			<h1 class="Theme-StoryTitle">The Long Road</h1>
			<div class="Theme-LeadIn">A journey through the hills.</div>
			<div class="Theme-Byline">By Alex Rivers</div>
		</section>
		<section class="Theme-Section">
			<div class="Theme-Layer-BodyText"><p>First paragraph of the story.</p></div>
		</section>
		<footer class="Theme-Footer">Built with Shorthand</footer>
	</div>';

	private function store_text( PostAPI $post_api, int $post_id, string $article ): void {
		$this->callPrivateMethod( $post_api, 'store_story_text', array( $post_id, $article ) );
	}

	private function make_post_api( Shorthand $shorthand ): PostAPI {
		return new PostAPI(
			$shorthand,
			$this->createMock( Options::class ),
			$this->createMock( Permissions::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class ),
			$this->createMock( StoryContentTransformer::class ),
			new StoryTextExtractor()
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

	public function test_store_story_text_puts_searchable_prose_in_post_content(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertCount( 1, $updated );
		$this->assertSame( 42, $updated[0]['ID'] );

		$content = stripslashes( $updated[0]['post_content'] );
		$this->assertStringNotContainsString( 'The Long Road', $content );
		$this->assertStringNotContainsString( 'Built with Shorthand', $content );
		$this->assertStringContainsString( 'By Alex Rivers', $content );
		$this->assertStringContainsString( 'First paragraph of the story.', $content );
	}

	public function test_store_story_text_keeps_the_title_section_out_of_the_excerpt(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertSame( 'First paragraph of the story.', stripslashes( $updated[0]['post_excerpt'] ) );
	}

	public function test_store_story_text_records_the_excerpt_it_wrote(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, self::STORY );

		$this->assertSame(
			(string) get_post_field( 'post_excerpt', 42, 'raw' ),
			(string) get_post_meta( 42, 'story_excerpt', true )
		);
	}

	public function test_store_story_text_leaves_an_author_written_excerpt_alone(): void {
		tests_wp_set_post_field( 42, 'post_excerpt', 'A hand-written summary.' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );
		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertArrayNotHasKey( 'post_excerpt', $updated[0] );
		$this->assertArrayHasKey( 'post_content', $updated[0] );
	}

	public function test_store_story_text_replaces_an_excerpt_it_wrote_before(): void {
		tests_wp_set_post_field( 42, 'post_excerpt', 'An earlier draft of the story.' );
		tests_wp_set_post_meta( 42, 'story_excerpt', 'An earlier draft of the story.' );

		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );
		$this->store_text( $post_api, 42, self::STORY );

		$updated = tests_wp_updated_posts();
		$this->assertSame( 'First paragraph of the story.', stripslashes( $updated[0]['post_excerpt'] ) );
	}

	public function test_store_story_text_writes_nothing_for_an_empty_body(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$this->store_text( $post_api, 42, '   ' );

		$this->assertSame( array(), tests_wp_updated_posts() );
	}

	public function test_store_story_text_flags_its_own_write_so_publishing_does_not_recurse(): void {
		$post_api = $this->make_post_api( $this->createMock( Shorthand::class ) );

		$flag_during_write = null;
		tests_wp_set_update_post_observer(
			static function () use ( $post_api, &$flag_during_write ): void {
				$flag_during_write = $post_api->is_storing_text();
			}
		);

		$this->assertFalse( $post_api->is_storing_text() );

		$this->store_text( $post_api, 42, self::STORY );

		$this->assertTrue( $flag_during_write );
		$this->assertFalse( $post_api->is_storing_text() );
	}
}
