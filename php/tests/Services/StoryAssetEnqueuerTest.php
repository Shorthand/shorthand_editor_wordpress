<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryAssetEnqueuer;
use Shorthand\Services\StoryKses;
use Shorthand\Tests\WordPressTestCase;

final class StoryAssetEnqueuerTest extends WordPressTestCase {

	public function test_enqueue_story_scripts_registers_external_and_inline_scripts(): void {
		$enqueuer = new StoryAssetEnqueuer();

		$enqueuer->enqueue_story_scripts(
			array(
				'<script src="https://cdn.example.test/app.js"></script>',
				'<script>window.story = &quot;ready&quot;;</script>',
			),
			7
		);

		$this->assertSame(
			array(
				array(
					'handle' => StoryKses::SCRIPT_HANDLE,
					'src'    => '',
					'deps'   => array(),
					'ver'    => false,
					'args'   => false,
				),
				array(
					'handle' => 'theshed-story-' . md5( 'https://cdn.example.test/app.js' ),
					'src'    => 'https://cdn.example.test/app.js',
					'deps'   => array(),
					'ver'    => 7,
					'args'   => true,
				),
			),
			\tests_wp_enqueued_scripts()
		);

		$this->assertSame(
			array(
				array(
					'handle' => StoryKses::SCRIPT_HANDLE,
					'data'   => 'window.story = "ready";',
				),
			),
			\tests_wp_inline_scripts()
		);
	}

	public function test_enqueue_head_assets_preserves_the_expected_handles_and_asset_order(): void {
		$enqueuer = new StoryAssetEnqueuer();

		$enqueuer->enqueue_head_assets(
			array(
				array(
					'type' => 'style-link',
					'href' => 'https://cdn.example.test/site.css',
				),
				array(
					'type'    => 'style-inline',
					'content' => '.story { color: red; }',
				),
				array(
					'type'  => 'script-src',
					'src'   => 'https://cdn.example.test/runtime.js',
					'defer' => true,
				),
				array(
					'type'    => 'script-inline',
					'content' => 'window.story = "ready";',
					'defer'   => false,
				),
			),
			true,
			12
		);

		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-style-0',
					'src'    => 'https://cdn.example.test/site.css',
					'deps'   => array(),
					'ver'    => 12,
					'media'  => 'all',
				),
				array(
					'handle' => 'theshed-story-inline-style-1',
					'src'    => '',
					'deps'   => array(),
					'ver'    => false,
					'media'  => 'all',
				),
			),
			\tests_wp_enqueued_styles()
		);

		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-inline-style-1',
					'src'    => '',
					'deps'   => array(),
					'ver'    => null,
					'media'  => 'all',
				),
			),
			\tests_wp_registered_styles()
		);

		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-inline-style-1',
					'data'   => '.story { color: red; }',
				),
			),
			\tests_wp_inline_styles()
		);

		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-head-script-0',
					'src'    => 'https://cdn.example.test/runtime.js',
					'deps'   => array(),
					'ver'    => 12,
					'args'   => array(
						'in_footer' => true,
						'strategy'  => 'defer',
					),
				),
				array(
					'handle' => 'theshed-story-head-inline-1',
					'src'    => '',
					'deps'   => array(),
					'ver'    => false,
					'args'   => false,
				),
			),
			\tests_wp_enqueued_scripts()
		);

		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-head-inline-1',
					'src'    => '',
					'deps'   => array(),
					'ver'    => null,
					'args'   => array(
						'in_footer' => true,
					),
				),
			),
			\tests_wp_registered_scripts()
		);

		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-head-inline-1',
					'data'   => 'window.story = "ready";',
				),
			),
			\tests_wp_inline_scripts()
		);
	}
}
