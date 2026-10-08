<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryKses;
use Shorthand\Services\StoryPlaceholders;
use Shorthand\Tests\WordPressTestCase;
use WP_Post;

/**
 * Placeholders resolve where the story head and body are printed.
 */
final class StoryKsesPlaceholdersTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		StoryPlaceholders::flush();
	}

	public function test_a_meta_tag_attribute_resolves(): void {
		$this->assertSame(
			'<meta property="og:title" content="Story title">' . "\n",
			$this->head( '<meta property="og:title" content="{{wp.story.title}}">', $this->story() )
		);
	}

	/**
	 * The resolver escapes once, and esc_attr() leaves a valid entity as it is.
	 */
	public function test_a_meta_tag_value_is_not_double_encoded(): void {
		$this->assertSame(
			'<meta property="og:title" content="Tom &amp; Jerry">' . "\n",
			$this->head( '<meta property="og:title" content="{{wp.story.title}}">', $this->story( array( 'post_title' => 'Tom & Jerry' ) ) )
		);
	}

	/**
	 * The head parser decodes each attribute value first, so an entity-encoded
	 * token cannot keep a placeholder literal in the head.
	 */
	public function test_an_entity_encoded_token_in_a_meta_tag_resolves(): void {
		$this->assertSame(
			'<meta property="og:title" content="Story title">' . "\n",
			$this->head( '<meta property="og:title" content="&#123;&#123;wp.story.title&#125;&#125;">', $this->story() )
		);
	}

	public function test_a_boolean_attribute_is_printed_as_before(): void {
		$this->assertSame(
			'<meta name="robots" content="noindex" data-flag="1">' . "\n",
			$this->head( '<meta name="robots" content="noindex" data-flag>', $this->story() )
		);
	}

	public function test_the_head_falls_back_to_the_current_post(): void {
		\tests_wp_set_current_post( $this->story() );

		$this->assertSame(
			'<meta property="og:title" content="Story title">' . "\n",
			$this->head( '<meta property="og:title" content="{{wp.story.title}}">' )
		);
	}

	public function test_the_head_is_printed_as_written_without_a_post(): void {
		$this->assertSame(
			'<meta property="og:title" content="{{wp.story.title}}">' . "\n",
			$this->head( '<meta property="og:title" content="{{wp.story.title}}">' )
		);
	}

	/**
	 * Scripts and styles are taken out before resolution, so their tokens stay literal.
	 */
	public function test_the_body_resolves_outside_scripts_and_styles(): void {
		$output = $this->body(
			'<p>{{wp.story.title}}</p><script>var t = "{{wp.story.title}}";</script><style>.a::after { content: "{{wp.story.title}}"; }</style>',
			$this->story()
		);

		$this->assertSame( '<p>Story title</p>', $output );
		$this->assertSame(
			array(
				array(
					'handle' => StoryKses::SCRIPT_HANDLE,
					'data'   => 'var t = "{{wp.story.title}}";',
				),
			),
			\tests_wp_inline_scripts()
		);
		$this->assertSame(
			array(
				array(
					'handle' => 'theshed-story-body-style-0',
					'data'   => '.a::after { content: "{{wp.story.title}}"; }',
				),
			),
			\tests_wp_inline_styles()
		);
	}

	/**
	 * The body is not decoded, so an author can show a token by encoding its braces.
	 */
	public function test_an_entity_encoded_token_in_the_body_stays_literal(): void {
		$body = '<p>&#123;&#123;wp.story.title&#125;&#125;</p>';

		$this->assertSame( $body, $this->body( $body, $this->story() ) );
	}

	public function test_the_body_falls_back_to_the_current_post(): void {
		\tests_wp_set_current_post( $this->story() );

		$this->assertSame( '<p>Story title</p>', $this->body( '<p>{{wp.story.title}}</p>' ) );
	}

	public function test_the_body_is_printed_as_written_without_a_post(): void {
		$this->assertSame( '<p>{{wp.story.title}}</p>', $this->body( '<p>{{wp.story.title}}</p>' ) );
	}

	public function test_the_head_and_body_share_resolved_values(): void {
		$calls = 0;
		\add_filter(
			'theshed_resolve_placeholder_story_title',
			static function ( string $value ) use ( &$calls ): string {
				++$calls;
				return $value;
			}
		);

		$this->head( '<meta property="og:title" content="{{wp.story.title}}">', $this->story() );
		$this->body( '<h1>{{wp.story.title}}</h1>', $this->story() );

		$this->assertSame( 1, $calls );
	}

	private function head( string $head, ?WP_Post $post = null ): string {
		ob_start();
		StoryKses::echo_meta_tags( $head, $post );

		return (string) ob_get_clean();
	}

	private function body( string $body, ?WP_Post $post = null ): string {
		ob_start();
		StoryKses::echo_extract_and_enqueue_assets( $body, null, $post );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function story( array $fields = array() ): WP_Post {
		return new WP_Post(
			array_merge(
				array(
					'ID'         => 42,
					'post_type'  => 'tse_story',
					'post_title' => 'Story title',
				),
				$fields
			)
		);
	}
}
