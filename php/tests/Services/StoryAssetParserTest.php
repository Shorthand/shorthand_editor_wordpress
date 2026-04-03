<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryAssetParser;
use Shorthand\Tests\WordPressTestCase;

final class StoryAssetParserTest extends WordPressTestCase {

	public function test_find_shorthand_tags_returns_unique_lowercase_tag_names(): void {
		$parser = new StoryAssetParser();

		$this->assertSame(
			array( 'sh-story', 'sh-slider' ),
			$parser->find_shorthand_tags( '<sh-story></sh-story><SH-SLIDER></SH-SLIDER><sh-story></sh-story>' )
		);
	}

	public function test_extract_script_tags_returns_content_without_scripts_and_original_script_tags(): void {
		$parser = new StoryAssetParser();

		$result = $parser->extract_script_tags(
			'<div>Story</div><script src="https://cdn.example.test/app.js"></script><script>window.story = true;</script>'
		);

		$this->assertSame( '<div>Story</div>', $result['content'] );
		$this->assertSame(
			array(
				'<script src="https://cdn.example.test/app.js"></script>',
				'<script>window.story = true;</script>',
			),
			$result['scripts']
		);
	}

	public function test_extract_style_tags_returns_style_blocks_and_content_without_them(): void {
		$parser = new StoryAssetParser();

		$result = $parser->extract_style_tags(
			'<style>.story { color: red; }</style><article>Body</article><style>.story p { color: blue; }</style>'
		);

		$this->assertSame( '<article>Body</article>', $result['content'] );
		$this->assertSame(
			array(
				'.story { color: red; }',
				'.story p { color: blue; }',
			),
			$result['styles']
		);
	}

	public function test_extract_meta_tags_parses_attribute_values_and_boolean_attributes(): void {
		$parser = new StoryAssetParser();

		$this->assertSame(
			array(
				array(
					'name'     => 'viewport',
					'content'  => 'width=device-width,initial-scale=1',
					'disabled' => true,
				),
				array(
					'property' => 'og:title',
					'content'  => 'Story & News',
				),
			),
			$parser->extract_meta_tags(
				'<meta name="viewport" content="width=device-width,initial-scale=1" disabled><meta property="og:title" content="Story &amp; News">'
			)
		);
	}

	public function test_parse_head_assets_preserves_asset_order_and_decodes_inline_script_entities(): void {
		$parser = new StoryAssetParser();

		$assets = $parser->parse_head_assets(
			'<link rel="stylesheet" href="https://cdn.example.test/site.css">' .
			'<style>.story { color: red; }</style>' .
			'<script src="https://cdn.example.test/runtime.js" defer></script>' .
			'<script>window.story = &quot;ready&quot;;</script>'
		);

		$this->assertSame(
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
			$assets
		);
	}
}
