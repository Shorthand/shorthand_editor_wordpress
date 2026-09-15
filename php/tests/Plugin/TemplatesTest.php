<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Core\Version;
use Shorthand\Plugin\Templates;
use Shorthand\Services\Options;
use Shorthand\Tests\WordPressTestCase;

final class TemplatesTest extends WordPressTestCase {

	private const POST_TYPE = 'tse_story';

	private const STORY_ID = 42;

	public function test_the_front_page_story_gets_the_plugin_template(): void {
		$this->stage_front_page_story();

		$this->assertSame(
			$this->plugin_template(),
			$this->templates()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_the_front_page_story_prefers_a_theme_override(): void {
		$this->stage_front_page_story();
		\tests_wp_set_located_template( 'single-tse-story.php', 'theme/single-tse-story.php' );

		$this->assertSame(
			'theme/single-tse-story.php',
			$this->templates()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_the_front_page_story_prefers_its_own_page_template(): void {
		$this->stage_front_page_story();
		\tests_wp_set_post_meta( self::STORY_ID, '_wp_page_template', 'custom.php' );
		\tests_wp_set_located_template( 'custom.php', 'theme/custom.php' );
		\tests_wp_set_located_template( 'single-tse-story.php', 'theme/single-tse-story.php' );

		$this->assertSame(
			'theme/custom.php',
			$this->templates()->front_page_template( 'theme/page.php' )
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
			$this->templates()->front_page_template( 'theme/home.php' )
		);
	}

	public function test_other_pages_are_left_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_front_page( false );

		$this->assertSame(
			'theme/single.php',
			$this->templates()->front_page_template( 'theme/single.php' )
		);
	}

	public function test_a_site_without_a_static_front_page_is_left_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_option( 'page_on_front', 0 );

		$this->assertSame(
			'theme/page.php',
			$this->templates()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_a_front_page_that_is_not_a_story_is_left_alone(): void {
		$this->stage_front_page_story();
		\tests_wp_set_post_type( self::STORY_ID, 'page' );

		$this->assertSame(
			'theme/page.php',
			$this->templates()->front_page_template( 'theme/page.php' )
		);
	}

	public function test_a_story_gets_the_plugin_template(): void {
		$this->stage_global_post( self::POST_TYPE );

		$this->assertSame(
			$this->plugin_template(),
			$this->templates()->single_template( 'theme/single.php' )
		);
	}

	public function test_a_story_prefers_a_theme_override(): void {
		$this->stage_global_post( self::POST_TYPE );
		\tests_wp_set_located_template( 'templates/single-tse_story.php', 'theme/single-tse_story.php' );

		$this->assertSame(
			'theme/single-tse_story.php',
			$this->templates()->single_template( 'theme/single.php' )
		);
	}

	public function test_other_post_types_are_left_alone(): void {
		$this->stage_global_post( 'post' );

		$this->assertSame(
			'theme/single.php',
			$this->templates()->single_template( 'theme/single.php' )
		);
	}

	public function test_the_front_page_filter_runs_late_on_template_include(): void {
		$this->templates()->register_templates();

		$hooks = \tests_wp_hook_callbacks( 'template_include' );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 'front_page_template', $hooks[0]['callback'][1] );
		$this->assertSame( 99, $hooks[0]['priority'] );
	}

	private function stage_front_page_story(): void {
		\tests_wp_set_front_page( true );
		\tests_wp_set_option( 'show_on_front', 'page' );
		\tests_wp_set_option( 'page_on_front', self::STORY_ID );
		\tests_wp_set_post_type( self::STORY_ID, self::POST_TYPE );
	}

	private function stage_global_post( string $post_type ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for the global core sets before the single_template filter runs.
		$GLOBALS['post'] = (object) array(
			'ID'        => self::STORY_ID,
			'post_type' => $post_type,
		);
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
