<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Tests\WordPressTestCase;

final class StoryTemplateTest extends WordPressTestCase {

	public function test_block_theme_renders_header_and_footer_template_parts(): void {
		\tests_wp_set_is_block_theme( true );

		$output = $this->render_template();

		$this->assertStringContainsString( '<div class="wp-site-blocks">', $output );
		$this->assertStringContainsString( '<header class="wp-block-template-part">', $output );
		$this->assertStringContainsString( '<footer class="wp-block-template-part">', $output );

		/* The parts must render before wp_head() so their block assets are enqueued in time. */
		$this->assertSame(
			array(
				'block_template_part:header',
				'block_template_part:footer',
				'wp_head',
				'wp_body_open',
				'wp_footer',
			),
			\tests_wp_template_calls()
		);
	}

	public function test_block_theme_prints_template_part_markup_inside_its_wrapper(): void {
		\tests_wp_set_is_block_theme( true );

		$output = $this->render_template();

		$this->assertMatchesRegularExpression(
			'#<header class="wp-block-template-part">\s*<!--part:header-->\s*</header>#',
			$output
		);
		$this->assertMatchesRegularExpression(
			'#<footer class="wp-block-template-part">\s*<!--part:footer-->\s*</footer>#',
			$output
		);
	}

	public function test_password_protected_story_prints_the_password_form(): void {
		\tests_wp_set_password_required( true );

		$output = $this->render_template();

		$this->assertStringContainsString( '<form class="post-password-form"></form>', $output );
	}

	public function test_password_protected_story_still_renders_the_footer(): void {
		\tests_wp_set_is_block_theme( true );
		\tests_wp_set_password_required( true );

		$output = $this->render_template();

		/* The password form must not short-circuit the closing markup. */
		$this->assertStringContainsString( '</body>', $output );
		$this->assertStringContainsString( '</html>', $output );
		$this->assertSame(
			array(
				'block_template_part:header',
				'block_template_part:footer',
				'wp_head',
				'wp_body_open',
				'get_the_password_form',
				'wp_footer',
			),
			\tests_wp_template_calls()
		);
	}

	public function test_password_protected_story_renders_the_classic_footer(): void {
		\tests_wp_set_password_required( true );

		$this->render_template();

		$this->assertSame(
			array(
				'get_header',
				'get_the_password_form',
				'get_footer',
			),
			\tests_wp_template_calls()
		);
	}

	public function test_classic_theme_continues_to_render_php_header_and_footer(): void {
		$output = $this->render_template();

		$this->assertSame( '', $output );
		$this->assertSame(
			array(
				'get_header',
				'get_footer',
			),
			\tests_wp_template_calls()
		);
	}

	public function test_the_story_body_renders_inside_the_loop(): void {
		$this->stage_story( '<article id="story"></article>' );

		$output = $this->render_template();

		$this->assertStringContainsString( '<article id="story"></article>', $output );
	}

	/**
	 * The whole-post form of get_post_meta() passes an empty key to the
	 * `get_post_metadata` filter and expects every key back, which the live
	 * preview filter cannot serve without calling itself.
	 */
	public function test_story_meta_is_read_one_key_at_a_time(): void {
		$this->stage_story( '<article id="story"></article>' );

		$this->render_template();

		$this->assertSame(
			array( 'story_body', 'story_version' ),
			\tests_wp_post_meta_reads()
		);
	}

	public function test_the_before_story_action_fires_for_each_story(): void {
		$this->stage_story( '<article id="story"></article>' );

		$this->render_template();

		$this->assertCount( 1, \tests_wp_actions_done( 'theshed_before_story' ) );
	}

	public function test_the_before_story_action_fires_ahead_of_the_story_body(): void {
		$this->stage_story( '<article id="story"></article>' );

		add_action(
			'theshed_before_story',
			static function (): void {
				echo '<!--notice-->';
			}
		);

		$output = $this->render_template();

		$this->assertMatchesRegularExpression( '#<!--notice-->.*<article id="story">#s', $output );
	}

	private function stage_story( string $body ): void {
		\tests_wp_set_have_posts( 1 );
		\tests_wp_set_post_meta( 42, 'story_body', $body );
		\tests_wp_set_post_meta( 42, 'story_version', 9 );
	}

	private function render_template(): string {
		$post = (object) array(
			'ID' => 42,
		);

		ob_start();
		require __DIR__ . '/../../src/templates/single-tse-story.php';
		return (string) ob_get_clean();
	}
}
