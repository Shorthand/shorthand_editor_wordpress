<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryKses;
use Shorthand\Tests\WordPressTestCase;

final class StoryKsesInlineCssTest extends WordPressTestCase {

	/**
	 * A `style` element is raw text, so HTML escaping would reach the reader.
	 */
	public function test_it_leaves_css_punctuation_alone(): void {
		$css = '.a > .b { background: url("data:image/svg+xml,%3Csvg%3E?a=1&b=2"); }';

		$this->assertSame( $css, StoryKses::sanitize_inline_css( $css ) );
	}

	public function test_it_keeps_author_css_from_closing_the_style_element(): void {
		$this->assertSame(
			'body {} style><script>alert(1)</script>',
			StoryKses::sanitize_inline_css( 'body {} </style><script>alert(1)</script>' )
		);
	}

	public function test_it_catches_a_mixed_case_closing_tag(): void {
		$this->assertSame( 'STYLE>', StoryKses::sanitize_inline_css( '</STYLE>' ) );
	}

	public function test_it_strips_nulls(): void {
		$this->assertSame( 'body {}', StoryKses::sanitize_inline_css( "bo\0dy {}" ) );
	}
}
