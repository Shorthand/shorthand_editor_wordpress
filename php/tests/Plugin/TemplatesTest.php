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

	public function test_story_meta_tags_print_on_the_front_page(): void {
		// The front page route: singular, and not single.
		\tests_wp_set_singular( self::POST_TYPE );
		$this->stage_global_post( self::POST_TYPE );
		\tests_wp_set_post_meta( self::STORY_ID, 'story_head', '<meta name="sh-head" content="yes">' );

		$this->expectOutputString( "<meta name=\"sh-head\" content=\"yes\">\n" );

		$this->templates()->single_head();
	}

	public function test_other_post_types_print_no_story_meta_tags(): void {
		\tests_wp_set_singular( 'page' );
		$this->stage_global_post( 'page' );
		\tests_wp_set_post_meta( self::STORY_ID, 'story_head', '<meta name="sh-head" content="yes">' );

		$this->expectOutputString( '' );

		$this->templates()->single_head();
	}

	public function test_the_story_template_filter_is_registered(): void {
		$this->templates()->register_templates();

		$hooks = \tests_wp_hook_callbacks( 'single_template' );

		$this->assertCount( 1, $hooks );
		$this->assertSame( 'single_template', $hooks[0]['callback'][1] );
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
