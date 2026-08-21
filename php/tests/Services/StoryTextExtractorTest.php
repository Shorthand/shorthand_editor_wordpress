<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryTextExtractor;
use Shorthand\Tests\WordPressTestCase;

final class StoryTextExtractorTest extends WordPressTestCase {

	private const STORY = '<div class="Theme-Story">
		<a class="Theme-skip-content-link" href="#main">Skip to main content</a>
		<div class="Theme-SocialIcons"><a href="#">Share on X</a></div>
		<section class="Theme-Section Theme-TitleSection Theme-Section-Position-1">
			<h1 class="Theme-StoryTitle Theme-TextSize-small">The Long Road</h1>
			<div class="Theme-LeadIn Theme-TextSize-xxsmall">A journey through the hills.</div>
			<div class="Theme-Byline">By Alex Rivers</div>
		</section>
		<section class="Theme-Section Theme-BackgroundScrollmationSection">
			<div class="Theme-Layer-BodyText">
				<div class="Theme-Layer-BodyText--inner">
					<p>First paragraph of the story.</p>
					<ul><li>Alpha</li><li>Beta</li></ul>
				</div>
			</div>
		</section>
		<footer class="Theme-Footer">Top<div class="Theme-Logos">Built with Shorthand</div></footer>
	</div>';

	private function extract( string $article ): array {
		$extractor = new StoryTextExtractor();
		return $extractor->extract( $article );
	}

	public function test_the_story_title_is_dropped_because_post_title_already_holds_it(): void {
		$text = $this->extract( self::STORY );

		$this->assertStringNotContainsString( 'The Long Road', $text['content'] );
	}

	public function test_an_unrelated_h1_survives(): void {
		$article = self::STORY . '<h1 class="custom-disclaimer-modal-title">Disclaimer</h1>';

		$text = $this->extract( $article );

		$this->assertStringContainsString( 'Disclaimer', $text['content'] );
	}

	public function test_content_keeps_the_byline_and_leadin_for_search(): void {
		$text = $this->extract( self::STORY );

		$this->assertStringContainsString( 'By Alex Rivers', $text['content'] );
		$this->assertStringContainsString( 'A journey through the hills.', $text['content'] );
		$this->assertStringContainsString( 'First paragraph of the story.', $text['content'] );
	}

	public function test_prose_excludes_the_title_section(): void {
		$text = $this->extract( self::STORY );

		$this->assertSame( 'First paragraph of the story. Alpha Beta', $text['prose'] );
	}

	public function test_chrome_is_dropped_from_both(): void {
		$text = $this->extract( self::STORY );

		foreach ( array( 'Skip to main content', 'Share on X', 'Built with Shorthand' ) as $chrome ) {
			$this->assertStringNotContainsString( $chrome, $text['content'] );
			$this->assertStringNotContainsString( $chrome, $text['prose'] );
		}
	}

	public function test_adjacent_elements_do_not_weld_into_one_word(): void {
		$text = $this->extract( '<ul><li><a>Title</a></li><li><a>Text Light</a></li></ul>' );

		$this->assertSame( 'Title Text Light', $text['content'] );
	}

	public function test_script_style_and_noscript_text_is_dropped(): void {
		$article = '<div class="Theme-Layer-BodyText"><p>Keep this.</p></div>'
			. '<script>var leaked = "script text";</script>'
			. '<style>.a { content: "style text"; }</style>'
			. '<noscript>Enable JavaScript</noscript>';

		$text = $this->extract( $article );

		$this->assertSame( 'Keep this.', $text['content'] );
		$this->assertSame( 'Keep this.', $text['prose'] );
	}

	public function test_an_unclosed_script_does_not_leak(): void {
		$article = '<div class="Theme-Layer-BodyText"><p>Keep this.</p></div><script>var leaked = 1;';

		$text = $this->extract( $article );

		$this->assertStringNotContainsString( 'leaked', $text['content'] );
	}

	public function test_entities_are_decoded_and_nbsp_becomes_a_space(): void {
		$article = '<div class="Theme-Layer-BodyText"><p>Marks&nbsp;&amp;&nbsp;Spencer &hellip; done</p></div>';

		$text = $this->extract( $article );

		$this->assertSame( 'Marks & Spencer … done', $text['prose'] );
	}

	public function test_utf8_text_survives_the_parser(): void {
		$article = '<div class="Theme-Layer-BodyText"><p>Über den Fluss — 日本語</p></div>';

		$text = $this->extract( $article );

		$this->assertSame( 'Über den Fluss — 日本語', $text['prose'] );
	}

	public function test_an_empty_body_yields_empty_strings(): void {
		$this->assertSame(
			array(
				'content' => '',
				'prose'   => '',
			),
			$this->extract( '   ' )
		);
	}

	public function test_a_story_without_body_text_containers_yields_no_prose(): void {
		$text = $this->extract( '<section class="Theme-Section Theme-MediaSection"><figure><figcaption>A photograph</figcaption></figure></section>' );

		$this->assertSame( 'A photograph', $text['content'] );
		$this->assertSame( '', $text['prose'] );
	}
}
