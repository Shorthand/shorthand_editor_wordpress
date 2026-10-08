<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Plugin\PostType;
use Shorthand\Services\StoryPlaceholders;
use Shorthand\Tests\WordPressTestCase;
use WP_Post;

/**
 * Placeholders are detected, resolved, filtered and escaped as the model says.
 */
final class StoryPlaceholdersTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();

		/* The memo is static, so a value resolved in one test would leak into the next. */
		StoryPlaceholders::flush();
	}

	/**
	 * @dataProvider matching_tokens
	 */
	public function test_it_detects_a_token( string $token, string $ns, string $key ): void {
		$this->assertSame( 1, preg_match( StoryPlaceholders::PATTERN, $token, $matches ) );
		$this->assertSame( $token, $matches[0] );
		$this->assertSame( $ns, $matches['ns'] );
		$this->assertSame( $key, $matches['key'] );
	}

	/**
	 * A detected token is never printed as literal placeholder text.
	 *
	 * @dataProvider matching_tokens
	 */
	public function test_it_replaces_every_detected_token( string $token ): void {
		$this->assertStringNotContainsString( '{{', StoryPlaceholders::replace( $token, $this->story() ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function matching_tokens(): array {
		return array(
			'story title'            => array( '{{wp.story.title}}', 'story', 'title' ),
			'story title, spaces'    => array( '{{ wp.story.title }}', 'story', 'title' ),
			'story title, tabs'      => array( "{{\twp.story.title\t}}", 'story', 'title' ),
			'story date'             => array( '{{wp.story.date}}', 'story', 'date' ),
			'story date_iso'         => array( '{{wp.story.date_iso}}', 'story', 'date_iso' ),
			'parent url'             => array( '{{wp.parent.url}}', 'parent', 'url' ),
			'meta'                   => array( '{{wp.meta.subtitle}}', 'meta', 'subtitle' ),
			'meta with a dot'        => array( '{{wp.meta.seo.title}}', 'meta', 'seo.title' ),
			'meta with a colon'      => array( '{{wp.meta.og:image}}', 'meta', 'og:image' ),
			'meta with a slash'      => array( '{{wp.meta.price/usd}}', 'meta', 'price/usd' ),
			'meta with spaces'       => array( '{{wp.meta.a b c}}', 'meta', 'a b c' ),
			'meta in uppercase'      => array( '{{wp.meta.Price}}', 'meta', 'Price' ),
			'meta in Japanese'       => array( '{{wp.meta.日本}}', 'meta', '日本' ),
			'meta, padded'           => array( '{{wp.meta.colour }}', 'meta', 'colour' ),
			'meta, protected'        => array( '{{wp.meta._price}}', 'meta', '_price' ),
			'meta named like story'  => array( '{{wp.meta.story.title}}', 'meta', 'story.title' ),
			'meta named like filter' => array( '{{wp.meta.filter.x}}', 'meta', 'filter.x' ),
			'terms category'         => array( '{{wp.terms.category}}', 'terms', 'category' ),
			'terms post_tag'         => array( '{{wp.terms.post_tag}}', 'terms', 'post_tag' ),
			'terms in uppercase'     => array( '{{wp.terms.Region}}', 'terms', 'Region' ),
			'terms with a dot'       => array( '{{wp.terms.my.tax}}', 'terms', 'my.tax' ),
			'terms named like story' => array( '{{wp.terms.story}}', 'terms', 'story' ),
			'filter with underscore' => array( '{{wp.filter.reading_time}}', 'filter', 'reading_time' ),
			'filter with hyphen'     => array( '{{wp.filter.reading-time}}', 'filter', 'reading-time' ),
			'filter, one letter'     => array( '{{wp.filter.a}}', 'filter', 'a' ),
			'filter with a digit'    => array( '{{wp.filter.x9}}', 'filter', 'x9' ),
			'filter, padded'         => array( '{{ wp.filter.byline }}', 'filter', 'byline' ),
			'meta at 255'            => array( '{{wp.meta.' . str_repeat( 'm', 255 ) . '}}', 'meta', str_repeat( 'm', 255 ) ),
			'meta at 255 codepoints' => array( '{{wp.meta.' . str_repeat( 'é', 255 ) . '}}', 'meta', str_repeat( 'é', 255 ) ),
			'terms at 32'            => array( '{{wp.terms.' . str_repeat( 't', 32 ) . '}}', 'terms', str_repeat( 't', 32 ) ),
			'filter at 64'           => array( '{{wp.filter.' . str_repeat( 'f', 64 ) . '}}', 'filter', str_repeat( 'f', 64 ) ),
		);
	}

	/**
	 * @dataProvider unmatched_text
	 */
	public function test_it_leaves_text_that_is_not_a_token( string $text ): void {
		$this->assertSame( 0, preg_match( StoryPlaceholders::PATTERN, $text ) );
		$this->assertSame( $text, StoryPlaceholders::replace( $text, $this->story() ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unmatched_text(): array {
		return array(
			'old title form'         => array( '{{wp.title}}' ),
			'old date form'          => array( '{{wp.date}}' ),
			'old date_iso form'      => array( '{{wp.date_iso}}' ),
			'story, no key'          => array( '{{wp.story}}' ),
			'story, empty key'       => array( '{{wp.story.}}' ),
			'story, unknown key'     => array( '{{wp.story.excerpt}}' ),
			'story, hyphenated key'  => array( '{{wp.story.date-iso}}' ),
			'uppercase key'          => array( '{{wp.story.Title}}' ),
			'uppercase namespace'    => array( '{{wp.Story.title}}' ),
			'uppercase prefix'       => array( '{{WP.story.title}}' ),
			'spaced dots'            => array( '{{ wp . story . title }}' ),
			'unknown namespace'      => array( '{{wp.frobnicate}}' ),
			'parent, no key'         => array( '{{wp.parent}}' ),
			'parent, unknown key'    => array( '{{wp.parent.title}}' ),
			'custom namespace'       => array( '{{wp.custom.x}}' ),
			'prefix only'            => array( '{{wp}}' ),
			'prefix and dot'         => array( '{{wp.}}' ),
			'no prefix'              => array( '{{title}}' ),
			'meta, empty key'        => array( '{{wp.meta.}}' ),
			'terms, empty key'       => array( '{{wp.terms.}}' ),
			'filter, empty key'      => array( '{{wp.filter.}}' ),
			'terms with a space'     => array( '{{wp.terms.with space}}' ),
			'meta with a brace'      => array( '{{wp.meta.a{b}}' ),
			'meta across a LF'       => array( "{{wp.meta.a\nb}}" ),
			'meta across a CR'       => array( "{{wp.meta.a\rb}}" ),
			'filter in uppercase'    => array( '{{wp.filter.Reading}}' ),
			'filter with a dot'      => array( '{{wp.filter.a.b}}' ),
			'filter with a space'    => array( '{{wp.filter.a b}}' ),
			'filter in Japanese'     => array( '{{wp.filter.日本}}' ),
			'filter with a colon'    => array( '{{wp.filter.a:b}}' ),
			'meta at 256'            => array( '{{wp.meta.' . str_repeat( 'm', 256 ) . '}}' ),
			'meta at 256 codepoints' => array( '{{wp.meta.' . str_repeat( 'é', 256 ) . '}}' ),
			'terms at 33'            => array( '{{wp.terms.' . str_repeat( 't', 33 ) . '}}' ),
			'filter at 65'           => array( '{{wp.filter.' . str_repeat( 'f', 65 ) . '}}' ),
			'CSS rule'               => array( '.card{margin:0}' ),
			'CSS media query'        => array( '@media screen{.a{b:c}}' ),
			'JS template literal'    => array( '`${y}`' ),
			'one closing brace'      => array( '{{ wp.story.title }' ),
			'single braces'          => array( '{wp.story.title}' ),
		);
	}

	public function test_a_meta_key_stops_at_the_first_closing_braces(): void {
		\tests_wp_set_post_meta( 42, 'a', 'value' );

		$this->assertSame( 1, preg_match( StoryPlaceholders::PATTERN, '{{wp.meta.a}}b}}', $matches ) );
		$this->assertSame( 'a', $matches['key'] );
		$this->assertSame( 'valueb}}', StoryPlaceholders::replace( '{{wp.meta.a}}b}}', $this->story() ) );
	}

	public function test_a_third_brace_stays_story_text(): void {
		$this->assertSame( '{Story title}', StoryPlaceholders::replace( '{{{wp.story.title}}}', $this->story() ) );
	}

	/**
	 * The bounded key quantifier keeps an unclosed token from running away.
	 * The spec measures under 1 ms; the bound here leaves room for a slow machine.
	 */
	public function test_an_unclosed_token_fails_fast(): void {
		$text = '{{wp.meta.' . str_repeat( 'x', 200000 );

		$start   = microtime( true );
		$matched = preg_match( StoryPlaceholders::PATTERN, $text );
		$elapsed = microtime( true ) - $start;

		$this->assertSame( 0, $matched );
		$this->assertSame( PREG_NO_ERROR, preg_last_error() );
		$this->assertLessThan( 0.05, $elapsed );
		$this->assertSame( $text, StoryPlaceholders::replace( $text, $this->story() ) );
	}

	/**
	 * @dataProvider matching_tokens
	 */
	public function test_a_hook_name_splits_back_into_its_namespace_and_key( string $token, string $ns, string $key ): void {
		$hook = StoryPlaceholders::hook_name( $ns, $key );

		$this->assertNotSame( 'theshed_resolve_placeholder', $hook );
		$this->assertStringStartsWith( 'theshed_resolve_placeholder_', $hook );
		$this->assertSame(
			array( $ns, $key ),
			explode( '_', substr( $hook, strlen( 'theshed_resolve_placeholder_' ) ), 2 )
		);
	}

	public function test_different_tokens_never_share_a_hook_name(): void {
		$hooks = array();
		foreach ( self::matching_tokens() as $case ) {
			$hooks[ $case[1] . "\n" . $case[2] ] = StoryPlaceholders::hook_name( $case[1], $case[2] );
		}

		$this->assertSame( $hooks, array_unique( $hooks ) );
	}

	/**
	 * Sanitizing or folding the key would merge distinct keys onto one hook.
	 */
	public function test_a_key_goes_into_its_hook_name_as_written(): void {
		$this->assertNotSame( StoryPlaceholders::hook_name( 'meta', 'Price' ), StoryPlaceholders::hook_name( 'meta', 'price' ) );
		$this->assertNotSame( StoryPlaceholders::hook_name( 'meta', 'og:image' ), StoryPlaceholders::hook_name( 'meta', 'ogimage' ) );
	}

	/**
	 * @dataProvider hook_names
	 */
	public function test_it_names_the_specific_filter( string $token, string $hook ): void {
		$this->assertSame( 1, preg_match( StoryPlaceholders::PATTERN, $token, $matches ) );
		$this->assertSame( $hook, StoryPlaceholders::hook_name( $matches['ns'], $matches['key'] ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function hook_names(): array {
		return array(
			'story title'     => array( '{{wp.story.title}}', 'theshed_resolve_placeholder_story_title' ),
			'story date_iso'  => array( '{{wp.story.date_iso}}', 'theshed_resolve_placeholder_story_date_iso' ),
			'parent url'      => array( '{{wp.parent.url}}', 'theshed_resolve_placeholder_parent_url' ),
			'meta'            => array( '{{wp.meta.subtitle}}', 'theshed_resolve_placeholder_meta_subtitle' ),
			'meta with colon' => array( '{{wp.meta.og:image}}', 'theshed_resolve_placeholder_meta_og:image' ),
			'protected meta'  => array( '{{wp.meta._price}}', 'theshed_resolve_placeholder_meta__price' ),
			'terms'           => array( '{{wp.terms.category}}', 'theshed_resolve_placeholder_terms_category' ),
			'filter'          => array( '{{wp.filter.reading_time}}', 'theshed_resolve_placeholder_filter_reading_time' ),
		);
	}

	/**
	 * The first `_` after `theshed_resolve_placeholder_` must always end the namespace.
	 */
	public function test_every_namespace_name_is_lowercase_letters(): void {
		foreach ( array_keys( StoryPlaceholders::NAMESPACES ) as $ns ) {
			$this->assertMatchesRegularExpression( '/^[a-z]+$/', $ns );
		}
	}

	public function test_the_pattern_detects_exactly_the_listed_namespaces(): void {
		preg_match_all( '/\(\?<ns>([^)]*)\)/', StoryPlaceholders::PATTERN, $names );

		$this->assertSame( array_keys( StoryPlaceholders::NAMESPACES ), $names[1] );
	}

	public function test_title_resolves_to_the_story_title(): void {
		$this->assertSame( '<h1>Story title</h1>', StoryPlaceholders::replace( '<h1>{{wp.story.title}}</h1>', $this->story() ) );
	}

	public function test_date_uses_the_site_date_format(): void {
		$this->assertSame( 'October 4, 2026', StoryPlaceholders::replace( '{{wp.story.date}}', $this->story() ) );

		StoryPlaceholders::flush();
		\tests_wp_set_option( 'date_format', 'Y/m/d' );

		$this->assertSame( '2026/10/04', StoryPlaceholders::replace( '{{wp.story.date}}', $this->story() ) );
	}

	public function test_date_iso_is_iso_8601_with_an_offset(): void {
		$this->assertSame( '2026-10-04T09:30:00+00:00', StoryPlaceholders::replace( '{{wp.story.date_iso}}', $this->story() ) );
	}

	public function test_parent_url_is_the_parent_permalink(): void {
		\tests_wp_set_post(
			7,
			new WP_Post(
				array(
					'ID'        => 7,
					'post_type' => 'page',
				)
			)
		);
		\tests_wp_set_permalink( 7, 'https://example.test/guides/' );

		$this->assertSame(
			'https://example.test/guides/',
			StoryPlaceholders::replace( '{{wp.parent.url}}', $this->story( array( 'post_parent' => 7 ) ) )
		);
	}

	public function test_parent_url_is_empty_without_a_parent(): void {
		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.parent.url}}', $this->story() ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_resolve_placeholder' ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_resolve_placeholder_parent_url' ) );
	}

	/**
	 * A deleted parent gives null, and get_permalink( null ) falls back to the
	 * global post: the story itself.
	 */
	public function test_a_deleted_parent_never_links_the_story_to_itself(): void {
		$story = $this->story( array( 'post_parent' => 7 ) );
		\tests_wp_set_current_post( $story );

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.parent.url}}', $story ) );
	}

	public function test_meta_resolves_to_the_stored_value(): void {
		\tests_wp_set_post_meta( 42, 'subtitle', 'A subtitle' );

		$this->assertSame( 'A subtitle', StoryPlaceholders::replace( '{{wp.meta.subtitle}}', $this->story() ) );
	}

	public function test_meta_is_read_by_the_key_as_written(): void {
		\tests_wp_set_post_meta( 42, 'Price', 'upper' );
		\tests_wp_set_post_meta( 42, 'price', 'lower' );

		$this->assertSame( 'upper lower', StoryPlaceholders::replace( '{{wp.meta.Price}} {{wp.meta.price}}', $this->story() ) );
	}

	public function test_missing_meta_resolves_to_empty_without_a_notice(): void {
		$this->assertSame( '[]', StoryPlaceholders::replace( '[{{wp.meta.subtitle}}]', $this->story() ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_resolve_placeholder' ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_resolve_placeholder_meta_subtitle' ) );
		$this->assertSame( array(), \tests_wp_doing_it_wrong() );
	}

	public function test_serialized_meta_resolves_to_empty(): void {
		\tests_wp_set_post_meta( 42, 'gallery', array( 1, 2 ) );

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.meta.gallery}}', $this->story() ) );
	}

	public function test_numeric_meta_resolves_to_its_digits(): void {
		\tests_wp_set_post_meta( 42, 'count', 7 );

		$this->assertSame( '7', StoryPlaceholders::replace( '{{wp.meta.count}}', $this->story() ) );
	}

	public function test_terms_resolve_to_the_linked_term_list(): void {
		\tests_wp_set_object_taxonomies( 'tse_story', array( 'category' ) );
		\tests_wp_set_term_list(
			42,
			'category',
			array(
				'<a href="https://example.test/news/" rel="tag">News</a>',
				'<a href="https://example.test/sport/" rel="tag">Sport</a>',
			)
		);

		$this->assertSame(
			'<a href="https://example.test/news/" rel="tag">News</a>, <a href="https://example.test/sport/" rel="tag">Sport</a>',
			StoryPlaceholders::replace( '{{wp.terms.category}}', $this->story() )
		);
	}

	public function test_terms_outside_the_post_type_resolve_to_empty(): void {
		\tests_wp_set_term_list( 42, 'region', array( '<a href="https://example.test/north/">North</a>' ) );

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.terms.region}}', $this->story() ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_resolve_placeholder' ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_resolve_placeholder_terms_region' ) );
	}

	public function test_a_site_can_serve_terms_the_post_type_does_not_carry(): void {
		\add_filter(
			'theshed_resolve_placeholder_terms_region',
			static function (): string {
				return '<a href="https://example.test/north/">North</a>';
			}
		);

		$this->assertSame(
			'<a href="https://example.test/north/">North</a>',
			StoryPlaceholders::replace( '{{wp.terms.region}}', $this->story() )
		);
	}

	/**
	 * @dataProvider failed_term_lists
	 * @param false|\WP_Error $term_list
	 */
	public function test_a_failed_term_list_resolves_to_empty( $term_list ): void {
		\tests_wp_set_object_taxonomies( 'tse_story', array( 'category' ) );
		\tests_wp_set_term_list( 42, 'category', $term_list );

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.terms.category}}', $this->story() ) );
	}

	/**
	 * @return array<string, array{0: false|\WP_Error}>
	 */
	public static function failed_term_lists(): array {
		return array(
			'no terms' => array( false ),
			'error'    => array( new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) ),
		);
	}

	public function test_an_unhandled_filter_token_resolves_to_empty_and_warns_once(): void {
		$output = StoryPlaceholders::replace( '[{{wp.filter.byline}}] [{{ wp.filter.byline }}]', $this->story() );
		StoryPlaceholders::replace( '{{wp.filter.byline}}', $this->story() );

		$this->assertSame( '[] []', $output );
		$this->assertSame(
			array(
				array(
					'function' => 'Shorthand\Services\StoryPlaceholders::replace',
					'message'  => 'The story placeholder {{wp.filter.byline}} has no value. Add a callback to the theshed_resolve_placeholder_filter_byline filter.',
					'version'  => '1.0.10',
				),
			),
			\tests_wp_doing_it_wrong()
		);
	}

	/**
	 * Any generic callback may serve the key, so the resolver cannot tell and stays silent.
	 *
	 * @dataProvider filter_hooks
	 */
	public function test_a_filter_token_with_a_callback_does_not_warn( string $hook ): void {
		\add_filter(
			$hook,
			static function ( string $value ): string {
				return $value;
			}
		);

		StoryPlaceholders::replace( '{{wp.filter.byline}}', $this->story() );

		$this->assertSame( array(), \tests_wp_doing_it_wrong() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function filter_hooks(): array {
		return array(
			'generic'  => array( 'theshed_resolve_placeholder' ),
			'specific' => array( 'theshed_resolve_placeholder_filter_byline' ),
		);
	}

	public function test_a_specific_callback_serves_a_filter_token(): void {
		\add_filter(
			'theshed_resolve_placeholder_filter_byline',
			static function (): string {
				return 'By Ada';
			}
		);

		$this->assertSame( 'By Ada', StoryPlaceholders::replace( '{{wp.filter.byline}}', $this->story() ) );
	}

	public function test_a_generic_callback_serves_the_filter_namespace(): void {
		\add_filter(
			'theshed_resolve_placeholder',
			static function ( string $value, string $ns, string $key ): string {
				return 'filter' === $ns ? strtoupper( $key ) : $value;
			},
			10,
			3
		);

		$this->assertSame(
			'BYLINE Story title',
			StoryPlaceholders::replace( '{{wp.filter.byline}} {{wp.story.title}}', $this->story() )
		);
	}

	/**
	 * The narrowest callback has the final word, whatever order they were added in.
	 */
	public function test_the_specific_filter_runs_after_the_generic_filter(): void {
		$order = array();
		\add_filter(
			'theshed_resolve_placeholder_story_title',
			static function ( string $value ) use ( &$order ): string {
				$order[] = 'specific';
				return $value . ' (specific)';
			}
		);
		\add_filter(
			'theshed_resolve_placeholder',
			static function ( string $value ) use ( &$order ): string {
				$order[] = 'generic';
				return $value . ' (generic)';
			}
		);

		$this->assertSame( 'Story title (generic) (specific)', StoryPlaceholders::replace( '{{wp.story.title}}', $this->story() ) );
		$this->assertSame( array( 'generic', 'specific' ), $order );
	}

	public function test_both_filters_receive_the_namespace_key_and_post(): void {
		$post = $this->story();

		StoryPlaceholders::replace( '{{ wp.story.title }}', $post );

		$this->assertSame( array( array( 'story', 'title', $post ) ), \tests_wp_get_filter_args( 'theshed_resolve_placeholder' ) );
		$this->assertSame( array( array( 'story', 'title', $post ) ), \tests_wp_get_filter_args( 'theshed_resolve_placeholder_story_title' ) );
	}

	/**
	 * @dataProvider filter_returns
	 * @param mixed $returned
	 */
	public function test_a_specific_filter_return_is_coerced_to_a_string( $returned, string $expected ): void {
		\add_filter(
			'theshed_resolve_placeholder_story_title',
			static function () use ( $returned ) {
				return $returned;
			}
		);

		$this->assertSame( $expected, StoryPlaceholders::replace( '{{wp.story.title}}', $this->story() ) );
	}

	/**
	 * @dataProvider filter_returns
	 * @param mixed $returned
	 */
	public function test_a_generic_filter_return_is_coerced_before_the_specific_filter( $returned, string $expected ): void {
		$received = null;
		\add_filter(
			'theshed_resolve_placeholder',
			static function () use ( $returned ) {
				return $returned;
			}
		);
		\add_filter(
			'theshed_resolve_placeholder_story_title',
			static function ( $value ) use ( &$received ) {
				$received = $value;
				return $value;
			}
		);

		$this->assertSame( $expected, StoryPlaceholders::replace( '{{wp.story.title}}', $this->story() ) );
		$this->assertSame( $expected, $received );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function filter_returns(): array {
		return array(
			'null'    => array( null, '' ),
			'false'   => array( false, '' ),
			'true'    => array( true, '1' ),
			'integer' => array( 7, '7' ),
			'float'   => array( 1.5, '1.5' ),
			'array'   => array( array( 'a' ), '' ),
			'object'  => array( new \stdClass(), '' ),
		);
	}

	public function test_a_callback_declines_by_returning_its_value(): void {
		\add_filter(
			'theshed_resolve_placeholder',
			static function ( string $value ): string {
				return $value;
			}
		);

		$this->assertSame( 'Story title', StoryPlaceholders::replace( '{{wp.story.title}}', $this->story() ) );
	}

	/**
	 * A catch-all callback that reads meta by key must not leak a denied key.
	 *
	 * @dataProvider denied_meta_keys
	 */
	public function test_a_denied_meta_key_is_never_read_and_skips_the_generic_filter( string $key ): void {
		\tests_wp_set_post_meta( 42, $key, 'secret' );
		\add_filter(
			'theshed_resolve_placeholder',
			static function ( $value, string $ns, string $meta_key, WP_Post $post ) {
				return \get_post_meta( $post->ID, $meta_key, true );
			},
			10,
			4
		);

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.meta.' . $key . '}}', $this->story() ) );
		$this->assertSame( array(), \tests_wp_post_meta_reads() );
		$this->assertSame( array(), \tests_wp_get_filter_args( 'theshed_resolve_placeholder' ) );
		$this->assertCount( 1, \tests_wp_get_filter_args( StoryPlaceholders::hook_name( 'meta', $key ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function denied_meta_keys(): array {
		$keys = array(
			'core lock'             => array( '_edit_lock' ),
			'underscore'            => array( '_price' ),
			'tab before underscore' => array( "\t_secret" ),
		);

		foreach ( PostType::PROTECTED_META_KEYS as $key ) {
			$keys[ $key ] = array( $key );
		}

		return $keys;
	}

	/**
	 * Naming one exact key in add_filter() is deliberate, so the specific filter still runs.
	 */
	public function test_a_specific_filter_can_serve_a_denied_meta_key(): void {
		\tests_wp_set_post_meta( 42, '_price', '10' );
		\add_filter(
			'theshed_resolve_placeholder_meta__price',
			static function ( string $value ): string {
				return '' === $value ? '£10' : 'leaked';
			}
		);

		$this->assertSame( '£10', StoryPlaceholders::replace( '{{wp.meta._price}}', $this->story() ) );
	}

	/**
	 * A site that un-protects the plugin's keys for its own reasons must not
	 * print the raw story HTML.
	 */
	public function test_the_plugin_keys_stay_denied_when_a_site_unprotects_them(): void {
		\tests_wp_set_post_meta( 42, 'story_body', '<p>The whole story</p>' );
		\add_filter(
			'is_protected_meta',
			static function (): bool {
				return false;
			}
		);

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.meta.story_body}}', $this->story() ) );
		$this->assertSame( array(), \tests_wp_post_meta_reads() );
	}

	public function test_a_key_the_site_protects_is_denied(): void {
		\tests_wp_set_post_meta( 42, 'internal_notes', 'secret' );
		\add_filter(
			'is_protected_meta',
			static function ( bool $is_protected, string $key ): bool {
				return $is_protected || 'internal_notes' === $key;
			},
			10,
			2
		);

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.meta.internal_notes}}', $this->story() ) );
		$this->assertSame( array(), \tests_wp_post_meta_reads() );
	}

	public function test_text_is_escaped_for_an_attribute(): void {
		\tests_wp_set_post_meta( 42, 'subtitle', 'Tom & "Jerry" <b>' );

		$this->assertSame(
			'Tom &amp; &quot;Jerry&quot; &lt;b&gt;',
			StoryPlaceholders::replace( '{{wp.meta.subtitle}}', $this->story() )
		);
	}

	public function test_markup_from_a_filter_callback_shows_as_text(): void {
		\add_filter(
			'theshed_resolve_placeholder_filter_byline',
			static function (): string {
				return '<strong>Ada</strong>';
			}
		);

		$this->assertSame( '&lt;strong&gt;Ada&lt;/strong&gt;', StoryPlaceholders::replace( '{{wp.filter.byline}}', $this->story() ) );
	}

	public function test_a_parent_url_with_a_disallowed_scheme_is_dropped(): void {
		\add_filter(
			'theshed_resolve_placeholder_parent_url',
			static function (): string {
				return 'javascript:alert(1)';
			}
		);

		$this->assertSame( '', StoryPlaceholders::replace( '{{wp.parent.url}}', $this->story() ) );
	}

	/**
	 * A callback can only produce markup wp_kses_post() allows, even for `terms`.
	 */
	public function test_terms_markup_passes_through_kses(): void {
		\add_filter(
			'theshed_resolve_placeholder_terms_category',
			static function (): string {
				return '<a href="https://example.test/news/">News</a><script>alert(1)</script>';
			}
		);

		$this->assertSame(
			'<a href="https://example.test/news/">News</a>alert(1)',
			StoryPlaceholders::replace( '{{wp.terms.category}}', $this->story() )
		);
	}

	/**
	 * The memo is keyed by post ID, so a second object for the same post shares it.
	 */
	public function test_each_token_resolves_once_per_request(): void {
		$calls = 0;
		\add_filter(
			'theshed_resolve_placeholder_story_title',
			static function ( string $value ) use ( &$calls ): string {
				++$calls;
				return $value;
			}
		);

		StoryPlaceholders::replace( '{{wp.story.title}} {{ wp.story.title }}', $this->story() );
		StoryPlaceholders::replace( '{{wp.story.title}}', $this->story() );

		$this->assertSame( 1, $calls );
	}

	public function test_flush_forgets_resolved_values(): void {
		$post = $this->story();
		StoryPlaceholders::replace( '{{wp.story.title}}', $post );

		$post->post_title = 'New title';
		$this->assertSame( 'Story title', StoryPlaceholders::replace( '{{wp.story.title}}', $post ) );

		StoryPlaceholders::flush();
		$this->assertSame( 'New title', StoryPlaceholders::replace( '{{wp.story.title}}', $post ) );
	}

	public function test_each_post_resolves_on_its_own(): void {
		$other = $this->story(
			array(
				'ID'         => 43,
				'post_title' => 'Other story',
			)
		);

		$this->assertSame( 'Story title', StoryPlaceholders::replace( '{{wp.story.title}}', $this->story() ) );
		$this->assertSame( 'Other story', StoryPlaceholders::replace( '{{wp.story.title}}', $other ) );
	}

	public function test_content_without_a_token_is_returned_as_it_is(): void {
		$content = '<p>Tom &amp; Jerry { not a token }</p>';

		$this->assertSame( $content, StoryPlaceholders::replace( $content, $this->story() ) );
		$this->assertSame( array(), \tests_wp_get_filter_args( 'theshed_resolve_placeholder' ) );
	}

	/**
	 * A mistyped token stays visible, beside the tokens that resolve.
	 */
	public function test_unknown_tokens_stay_beside_resolved_ones(): void {
		$this->assertSame(
			'Story title {{wp.story.titel}} {{wp.title}}',
			StoryPlaceholders::replace( '{{wp.story.title}} {{wp.story.titel}} {{wp.title}}', $this->story() )
		);
	}

	/**
	 * Replacement is one pass, so a value that looks like a token prints as it is.
	 */
	public function test_a_resolved_value_is_never_resolved_again(): void {
		$post = $this->story( array( 'post_title' => '{{wp.story.date}}' ) );

		$this->assertSame( '{{wp.story.date}}', StoryPlaceholders::replace( '{{wp.story.title}}', $post ) );
	}

	public function test_invalid_utf8_leaves_the_content_as_it_was(): void {
		$content = "{{wp.story.title}}\xff";

		$this->assertSame( $content, StoryPlaceholders::replace( $content, $this->story() ) );
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function story( array $fields = array() ): WP_Post {
		return new WP_Post(
			array_merge(
				array(
					'ID'          => 42,
					'post_type'   => 'tse_story',
					'post_title'  => 'Story title',
					'post_date'   => '2026-10-04 09:30:00',
					'post_parent' => 0,
				),
				$fields
			)
		);
	}
}
