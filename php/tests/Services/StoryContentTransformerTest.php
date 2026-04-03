<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryContentTransformer;
use Shorthand\Tests\WordPressTestCase;

final class StoryContentTransformerTest extends WordPressTestCase {

	public function test_rewrite_story_bundle_paths_updates_relative_asset_urls(): void {
		$transformer = new StoryContentTransformer();
		$bundle_url  = 'https://example.test/uploads/shorthand/44/story-abc';
		$content     = '<link href="./assets/site.css"><script src="./static/app.js"></script><link href="./theme-main.min.css">';

		$this->assertSame(
			'<link href="https://example.test/uploads/shorthand/44/story-abc/assets/site.css"><script src="https://example.test/uploads/shorthand/44/story-abc/static/app.js"></script><link href="https://example.test/uploads/shorthand/44/story-abc/theme-main.min.css">',
			$transformer->rewrite_story_bundle_paths( $bundle_url, $content )
		);
	}

	public function test_apply_processing_rule_set_updates_head_and_article_content(): void {
		$transformer = new StoryContentTransformer();
		$rules_json  = '{"head":[{"query":"/<title>/","replace":"<title data-test=\\"true\\">"}],"body":[{"query":"/draft/","replace":"published"},{"query":"/story/","replace":"article"}]}';

		$result = $transformer->apply_processing_rule_set(
			'<title>Story</title>',
			'<p>draft story</p>',
			$rules_json
		);

		$this->assertSame( '<title data-test="true">Story</title>', $result['head'] );
		$this->assertSame( '<p>published article</p>', $result['article'] );
	}

	public function test_apply_processing_rule_set_ignores_invalid_rule_json(): void {
		$transformer = new StoryContentTransformer();

		$result = $transformer->apply_processing_rule_set(
			'<title>Story</title>',
			'<p>draft story</p>',
			'{"body":'
		);

		$this->assertSame( '<title>Story</title>', $result['head'] );
		$this->assertSame( '<p>draft story</p>', $result['article'] );
	}
}
