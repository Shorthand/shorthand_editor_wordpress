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
			'body {} <\\/style><script>alert(1)</script>',
			StoryKses::sanitize_inline_css( 'body {} </style><script>alert(1)</script>' )
		);
	}

	/**
	 * A closing tag must not survive by nesting the fragment the sanitiser defuses.
	 *
	 * @dataProvider disguised_closing_tags
	 */
	public function test_it_never_leaves_a_closing_tag_behind( string $css ): void {
		$this->assertDoesNotMatchRegularExpression( '#</style[\s/>]#i', StoryKses::sanitize_inline_css( $css ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function disguised_closing_tags(): array {
		return array(
			'nested'        => array( '</</style>' ),
			'doubly nested' => array( '</</</style>' ),
			'mixed case'    => array( '</STYLE>' ),
			'whitespace'    => array( "</style\n>" ),
			'self closing'  => array( '</style/>' ),
		);
	}

	/**
	 * A tag name that merely starts with `style` is text to the parser.
	 */
	public function test_it_leaves_other_closing_tags_alone(): void {
		$css = '/* </stylesheet> */ body {}';

		$this->assertSame( $css, StoryKses::sanitize_inline_css( $css ) );
	}

	public function test_it_strips_nulls(): void {
		$this->assertSame( 'body {}', StoryKses::sanitize_inline_css( "bo\0dy {}" ) );
	}
}
