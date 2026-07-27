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
		$this->assertSame(
			array(
				'wp_head',
				'wp_body_open',
				'block_template_part:header',
				'block_template_part:footer',
				'wp_footer',
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

	private function render_template(): string {
		$post = (object) array(
			'ID' => 42,
		);

		ob_start();
		require __DIR__ . '/../../src/templates/single-tse-story.php';
		return (string) ob_get_clean();
	}
}
