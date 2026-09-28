<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Core\Version;
use Shorthand\Plugin\FrontPage;
use Shorthand\Plugin\Templates;
use Shorthand\Services\Options;
use Shorthand\Tests\WordPressTestCase;

final class FrontPageTest extends WordPressTestCase {

	private const POST_TYPE = 'tse_story';

	private const STORY_ID = 42;

	public function test_the_front_page_story_gets_the_plugin_template(): void {
		$this->stage_front_page_story();

		$this->assertSame(
			$this->plugin_template(),
			$this->front_page()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_the_front_page_story_prefers_a_theme_override(): void {
		$this->stage_front_page_story();
		\tests_wp_set_located_template( 'single-tse-story.php', 'theme/single-tse-story.php' );

		$this->assertSame(
			'theme/single-tse-story.php',
			$this->front_page()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_the_front_page_story_prefers_its_own_page_template(): void {
		$this->stage_front_page_story();
		\tests_wp_set_post_meta( self::STORY_ID, '_wp_page_template', 'custom.php' );
		\tests_wp_set_located_template( 'custom.php', 'theme/custom.php' );
		\tests_wp_set_located_template( 'single-tse-story.php', 'theme/single-tse-story.php' );

		$this->assertSame(
			'theme/custom.php',
			$this->front_page()->front_page_template( 'theme/page.php' )
		);
	}

	/**
	 * `page_on_front` keeps its value after the site switches back to showing
	 * latest posts, and is_front_page() is true for the blog index.
	 */
	public function test_a_stale_front_page_option_leaves_the_blog_index_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_option( 'show_on_front', 'posts' );

		$this->assertSame(
			'theme/home.php',
			$this->front_page()->front_page_template( 'theme/home.php' )
		);
	}

	public function test_other_pages_are_left_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_front_page( false );

		$this->assertSame(
			'theme/single.php',
			$this->front_page()->front_page_template( 'theme/single.php' )
		);
	}

	public function test_a_site_without_a_static_front_page_is_left_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_option( 'page_on_front', 0 );

		$this->assertSame(
			'theme/page.php',
			$this->front_page()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_a_front_page_that_is_not_a_story_is_left_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_post_type( self::STORY_ID, 'page' );

		$this->assertSame(
			'theme/page.php',
			$this->front_page()->front_page_template( 'theme/page.php' )
		);
	}

	/**
	 * Without a post type the query is constrained to `page` and finds nothing,
	 * which ends in a 404 rather than the story.
	 */
	public function test_the_front_page_query_asks_for_the_story_post_type(): void {
		$this->stage_front_page_story();
		$query = $this->front_page_query();

		$this->front_page()->find_front_page_story( $query );

		$this->assertSame( self::POST_TYPE, $query->get( 'post_type' ) );
	}

	public function test_a_stale_front_page_option_leaves_the_query_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_option( 'show_on_front', 'posts' );
		$query = $this->front_page_query();

		$this->front_page()->find_front_page_story( $query );

		$this->assertSame( '', $query->get( 'post_type' ) );
	}

	public function test_a_front_page_that_is_not_a_story_leaves_the_query_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_post_type( self::STORY_ID, 'page' );
		$query = $this->front_page_query();

		$this->front_page()->find_front_page_story( $query );

		$this->assertSame( '', $query->get( 'post_type' ) );
	}

	public function test_another_page_request_leaves_the_query_alone(): void {
		$this->stage_front_page_story();
		$query = new \WP_Query( array( 'page_id' => 7 ) );

		$this->front_page()->find_front_page_story( $query );

		$this->assertSame( '', $query->get( 'post_type' ) );
	}

	public function test_a_secondary_query_is_left_alone(): void {
		$this->stage_front_page_story();
		$query             = $this->front_page_query();
		$query->main_query = false;

		$this->front_page()->find_front_page_story( $query );

		$this->assertSame( '', $query->get( 'post_type' ) );
	}

	public function test_the_home_page_control_offers_stories_after_pages(): void {
		\tests_wp_set_posts_query_result( array( 'story-one', 'story-two' ) );

		$this->assertSame(
			array( 'page-one', 'story-one', 'story-two' ),
			$this->front_page()->offer_stories_as_front_page(
				array( 'page-one' ),
				array( 'name' => 'page_on_front' )
			)
		);
	}

	/**
	 * wp_dropdown_pages() prints nothing at all for an empty list, so a site
	 * with no pages has no control to add stories to.
	 */
	public function test_the_home_page_control_offers_stories_on_a_site_with_no_pages(): void {
		\tests_wp_set_posts_query_result( array( 'story-one' ) );

		$this->assertSame(
			array( 'story-one' ),
			$this->front_page()->offer_stories_as_front_page(
				array(),
				array( 'name' => 'page_on_front' )
			)
		);
	}

	public function test_the_home_page_control_offers_published_stories_by_title(): void {
		$this->front_page()->offer_stories_as_front_page( array(), array( 'name' => 'page_on_front' ) );

		$queries = \tests_wp_posts_queries();

		$this->assertCount( 1, $queries );
		$this->assertSame( self::POST_TYPE, $queries[0]['post_type'] );
		$this->assertSame( 'publish', $queries[0]['post_status'] );
		$this->assertSame( -1, $queries[0]['numberposts'] );
		$this->assertSame( 'title', $queries[0]['orderby'] );
	}

	public function test_other_page_lists_are_left_alone(): void {
		\tests_wp_set_posts_query_result( array( 'story-one' ) );

		$this->assertSame(
			array( 'page-one' ),
			$this->front_page()->offer_stories_as_front_page(
				array( 'page-one' ),
				array( 'name' => 'page_for_posts' )
			)
		);
		$this->assertSame( array(), \tests_wp_posts_queries() );
	}

	public function test_the_front_page_filter_runs_late_on_template_include(): void {
		$this->front_page()->init();

		$hooks = \tests_wp_hook_callbacks( 'template_include' );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 'front_page_template', $hooks[0]['callback'][1] );
		$this->assertSame( 99, $hooks[0]['priority'] );
	}

	public function test_the_query_and_the_settings_control_are_hooked(): void {
		$this->front_page()->init();

		$this->assertSame(
			'find_front_page_story',
			\tests_wp_hook_callbacks( 'pre_get_posts' )[0]['callback'][1]
		);
		$this->assertSame(
			'offer_stories_as_front_page',
			\tests_wp_hook_callbacks( 'get_pages' )[0]['callback'][1]
		);
	}

	private function stage_front_page_story(): void {
		\tests_wp_set_front_page( true );
		\tests_wp_set_option( 'show_on_front', 'page' );
		\tests_wp_set_option( 'page_on_front', self::STORY_ID );
		\tests_wp_set_post_type( self::STORY_ID, self::POST_TYPE );
	}

	/**
	 * The main query as core hands it to `pre_get_posts` on a static front page
	 * request: parse_query() has put the front page in `page_id` and named no
	 * post type.
	 */
	private function front_page_query(): \WP_Query {
		return new \WP_Query( array( 'page_id' => self::STORY_ID ) );
	}

	private function front_page(): FrontPage {
		return new FrontPage( self::POST_TYPE, $this->templates() );
	}

	private function templates(): Templates {
		return new Templates(
			self::POST_TYPE,
			$this->instantiateWithoutConstructor( Options::class ),
			$this->plugin_version()
		);
	}

	private function plugin_version(): Version {
		return new class() extends Version {
			public function get_plugin_path( string $file = '' ): string {
				return dirname( __DIR__, 2 ) . '/src/' . $file;
			}
		};
	}

	private function plugin_template(): string {
		return dirname( __DIR__, 2 ) . '/src/templates/single-tse-story.php';
	}
}
