<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryTextExtractor;
use Shorthand\Tests\WordPressTestCase;

final class StoryTextExtractorTest extends WordPressTestCase {

	private const STORY = '<div class="Theme-Story">
		<a href="#article" id="skip-link" class="Theme-skip-content-link">Skip to main content</a>
		<nav class="Navigation Theme-NavigationBar"><ul><li><a href="#s1">Jump to chapter one</a></li></ul></nav>
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
					<video><p>Your browser does not support this video</p></video>
					<div class="Responsive--hide-portrait"><p>A caption</p></div>
					<div class="Responsive--hide-landscape"><p>A caption</p></div>
					<div class="Display--none Display--md-block"><p>A note</p></div>
					<div class="Display--md-none"><p>A note</p></div>
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

		$this->assertSame( 'First paragraph of the story. A caption A caption A note A note Alpha Beta', $text['prose'] );
	}

	public function test_chrome_is_dropped_from_both(): void {
		$text = $this->extract( self::STORY );

		foreach ( array( 'Skip to main content', 'Jump to chapter one', 'Share on X', 'Built with Shorthand' ) as $chrome ) {
			$this->assertStringNotContainsString( $chrome, $text['content'] );
			$this->assertStringNotContainsString( $chrome, $text['prose'] );
		}
	}

	/**
	 * Only the engine's own skip link counts as furniture.
	 *
	 * The engine element is matched on `Theme-skip-content-link`, never on
	 * its `skip-link` id. A `skip-link` class a customer theme injects for
	 * itself is a name nobody guarantees, so it stays in the text.
	 */
	public function test_only_the_engine_skip_link_is_dropped(): void {
		$engine = $this->extract( '<a href="#article" id="skip-link" class="Theme-skip-content-link">Skip to main content</a><p>Body.</p>' );
		$theme  = $this->extract( '<a href="#" class="skip-link">Skip to main content</a><p>Body.</p>' );

		$this->assertSame( 'Body.', $engine['content'] );
		$this->assertSame( 'Skip to main content Body.', $theme['content'] );
	}

	/**
	 * The parser must not dereference anything a story declares.
	 *
	 * `loadHTML()` has no DTD subset machinery, so a `SYSTEM` identifier is
	 * never fetched and an entity is never substituted. Nothing in the parse
	 * flags is what makes that true, so pin it: a future switch to
	 * `loadXML()`, or a libxml that grows the behaviour, must fail here.
	 */
	public function test_external_entities_are_never_resolved(): void {
		$secret = tempnam( sys_get_temp_dir(), 'tse' );
		$this->assertIsString( $secret, 'The temporary directory is not writable.' );
		file_put_contents( $secret, 'LEAKED' );

		$declared = '<!DOCTYPE html [<!ENTITY payload SYSTEM "file://' . $secret . '">]>'
			. '<html><body><p>&payload;</p></body></html>';

		try {
			$text = $this->extract( $declared );
		} finally {
			unlink( $secret );
		}

		$this->assertStringNotContainsString( 'LEAKED', $text['content'] );
	}

	public function test_an_unreachable_doctype_does_not_fail_the_parse(): void {
		$text = $this->extract(
			'<!DOCTYPE html SYSTEM "file:///nowhere/absent.dtd"><html><body><p>Body.</p></body></html>'
		);

		$this->assertSame( 'Body.', $text['content'] );
	}

	public function test_adjacent_elements_do_not_weld_into_one_word(): void {
		$text = $this->extract( '<ul><li><a>Title</a></li><li><a>Text Light</a></li></ul>' );

		$this->assertSame( 'Title Text Light', $text['content'] );
	}

	public function test_video_fallback_text_is_dropped(): void {
		$text = $this->extract( self::STORY );

		$this->assertStringNotContainsString( 'does not support this video', $text['content'] );
	}

	/**
	 * Text emitted once per screen width is indexed once per screen width.
	 *
	 * The classes that mark the narrow copy — `Responsive--hide-landscape`,
	 * `Display--md-none` — are layout utilities, not `Theme-` names, so the
	 * engine may rename them at any time. Keying on them would fail silently
	 * when it did. The duplicate costs about 1% of a story's indexed length
	 * and changes no search result, so it is left in.
	 */
	public function test_text_repeated_for_each_screen_width_is_kept(): void {
		$text = $this->extract( self::STORY );

		$this->assertSame( 2, substr_count( $text['content'], 'A caption' ) );
		$this->assertSame( 2, substr_count( $text['content'], 'A note' ) );
	}

	public function test_inline_elements_stay_part_of_their_sentence(): void {
		$text = $this->extract( '<div class="Theme-Layer-BodyText"><p>Hello <em>world</em>, and <strong>welcome</strong>!</p></div>' );

		$this->assertSame( 'Hello world, and welcome!', $text['prose'] );
	}

	public function test_a_nested_body_text_container_is_not_counted_twice(): void {
		$article = '<div class="Theme-Layer-BodyText">'
			. '<div class="Theme-Layer-BodyText"><p>Only once.</p></div>'
			. '</div>';

		$text = $this->extract( $article );

		$this->assertSame( 'Only once.', $text['prose'] );
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
