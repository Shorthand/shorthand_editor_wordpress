<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Plugin\PostType;
use WP_Post;

/**
 * Resolves the placeholders an author writes into a Shorthand story.
 *
 * A placeholder is a `{{wp.<namespace>.<key>}}` token in the story head or
 * body. Each one resolves to a WordPress value, passes through the
 * `theshed_resolve_placeholder` filter and then its own specific filter, and
 * is escaped by its namespace's type. Text that only looks like a
 * placeholder is left exactly as written.
 *
 * @link https://github.com/Shorthand/shorthand_editor_wordpress/blob/master/docs/models/story-placeholders.md
 */
class StoryPlaceholders {

	/**
	 * Detects every placeholder, and nothing that is not one.
	 *
	 * Each alternative sets the same two named groups, `ns` and `key`. The key
	 * bounds come from WordPress: 255 is `meta_key varchar(255)`
	 * (wp-admin/includes/schema.php) and 32 is the taxonomy name limit in
	 * register_taxonomy() (wp-includes/taxonomy.php). 64 for a filter key is the
	 * plugin's own.
	 */
	public const PATTERN = '/\{\{[ \t]*wp\.(?|(?<ns>story)\.(?<key>date_iso|date|title)|(?<ns>parent)\.(?<key>url)|(?<ns>meta)\.(?<key>[^{}\r\n]{1,255}?)|(?<ns>terms)\.(?<key>[^\s{}]{1,32})|(?<ns>filter)\.(?<key>[a-z0-9_-]{1,64}))[ \t]*\}\}/u';

	/**
	 * The type of each namespace, which fixes how its values are escaped.
	 *
	 * A namespace name is lowercase letters only, so the first `_` after
	 * `theshed_resolve_placeholder_` in a hook name always ends the namespace.
	 */
	public const NAMESPACES = array(
		'story'  => 'text',
		'parent' => 'url',
		'meta'   => 'text',
		'terms'  => 'html',
		'filter' => 'text',
	);

	/**
	 * Escaped values resolved in this request, by post ID, namespace and key.
	 *
	 * @var array<int, array<string, array<string, string>>>
	 */
	private static $resolved = array();

	/**
	 * Replaces every placeholder in the content with its resolved value.
	 *
	 * @param string  $content The story head or body, or one attribute value.
	 * @param WP_Post $post    The story being rendered or previewed.
	 * @return string The content with each placeholder replaced.
	 */
	public static function replace( string $content, WP_Post $post ): string {
		if ( false === strpos( $content, '{{' ) ) {
			return $content;
		}

		$replaced = preg_replace_callback(
			self::PATTERN,
			static function ( array $matches ) use ( $post ): string {
				return self::resolve( $matches['ns'], $matches['key'], $post );
			},
			$content
		);

		// A PCRE error, such as invalid UTF-8, returns null. Leave the content as it was.
		return null === $replaced ? $content : $replaced;
	}

	/**
	 * Builds the name of a placeholder's specific filter.
	 *
	 * The key goes in exactly as written, with no sanitizing or case folding,
	 * so two different keys never share a hook.
	 *
	 * @param string $ns  The placeholder's namespace.
	 * @param string $key The placeholder's key.
	 * @return string The hook name, such as `theshed_resolve_placeholder_story_title`.
	 */
	public static function hook_name( string $ns, string $key ): string {
		return "theshed_resolve_placeholder_{$ns}_{$key}";
	}

	/**
	 * Forgets every value resolved so far in this request.
	 */
	public static function flush(): void {
		self::$resolved = array();
	}

	/**
	 * Resolves one placeholder to its escaped value.
	 *
	 * @param string  $ns   The placeholder's namespace.
	 * @param string  $key  The placeholder's key.
	 * @param WP_Post $post The story being rendered or previewed.
	 * @return string The escaped value.
	 */
	private static function resolve( string $ns, string $key, WP_Post $post ): string {
		$post_id = (int) $post->ID;

		if ( isset( self::$resolved[ $post_id ][ $ns ][ $key ] ) ) {
			return self::$resolved[ $post_id ][ $ns ][ $key ];
		}

		$hook   = self::hook_name( $ns, $key );
		$denied = 'meta' === $ns && self::is_denied( $key );
		$value  = $denied ? '' : self::get_default( $ns, $key, $post );

		if ( ! $denied ) {
			/**
			 * Filters the value of any story placeholder.
			 *
			 * Runs before the placeholder's own specific filter, and is skipped for
			 * a denied meta key. Return the value unescaped: the plugin escapes it
			 * afterwards, by the namespace's type.
			 *
			 * @link https://github.com/Shorthand/shorthand_editor_wordpress/blob/master/docs/models/story-placeholders.md
			 *
			 * @param string  $value     The default value.
			 * @param string  $namespace The placeholder's namespace: story, parent, meta, terms or filter.
			 * @param string  $key       The placeholder's key, exactly as written.
			 * @param WP_Post $post      The story being rendered or previewed.
			 */
			$value = self::to_string( apply_filters( 'theshed_resolve_placeholder', $value, $ns, $key, $post ) );
		}

		/**
		 * Filters the value of one story placeholder.
		 *
		 * The dynamic portions of the hook name, `$namespace` and `$key`, refer to
		 * the placeholder's namespace and key, as in
		 * `theshed_resolve_placeholder_story_title`. Runs last, so it has the final
		 * word. Return the value unescaped: the plugin escapes it afterwards.
		 *
		 * @link https://github.com/Shorthand/shorthand_editor_wordpress/blob/master/docs/models/story-placeholders.md
		 *
		 * @param string  $value     The value from `theshed_resolve_placeholder`, or `''` for a denied meta key.
		 * @param string  $namespace The placeholder's namespace: story, parent, meta, terms or filter.
		 * @param string  $key       The placeholder's key, exactly as written.
		 * @param WP_Post $post      The story being rendered or previewed.
		 */
		$value = self::to_string( apply_filters( $hook, $value, $ns, $key, $post ) );

		if ( 'filter' === $ns && ! has_filter( 'theshed_resolve_placeholder' ) && ! has_filter( $hook ) ) {
			_doing_it_wrong(
				self::class . '::replace',
				sprintf(
					/* translators: 1: A story placeholder, such as {{wp.filter.byline}}. 2: A filter hook name. */
					esc_html__( 'The story placeholder %1$s has no value. Add a callback to the %2$s filter.', 'the-shorthand-editor' ),
					esc_html( '{{wp.filter.' . $key . '}}' ),
					esc_html( $hook )
				),
				'1.0.10'
			);
		}

		self::$resolved[ $post_id ][ $ns ][ $key ] = self::escape( $ns, $value );

		return self::$resolved[ $post_id ][ $ns ][ $key ];
	}

	/**
	 * Computes a placeholder's built-in value, before any filter runs.
	 *
	 * @param string  $ns   The placeholder's namespace.
	 * @param string  $key  The placeholder's key.
	 * @param WP_Post $post The story being rendered or previewed.
	 * @return string The default value, unescaped.
	 */
	private static function get_default( string $ns, string $key, WP_Post $post ): string {
		switch ( $ns ) {
			case 'story':
				if ( 'title' === $key ) {
					return self::to_string( get_the_title( $post ) );
				}

				// The pattern allows only `date` and `date_iso` besides `title`.
				return self::to_string( get_the_date( 'date_iso' === $key ? 'c' : '', $post ) );

			case 'parent':
				// A deleted parent gives null, and get_permalink( null ) would link the story to itself.
				$parent = get_post_parent( $post );
				return $parent ? self::to_string( get_permalink( $parent ) ) : '';

			case 'meta':
				return self::to_string( get_post_meta( $post->ID, $key, true ) );

			case 'terms':
				if ( ! is_object_in_taxonomy( $post->post_type, $key ) ) {
					return '';
				}

				$terms = get_the_term_list( $post->ID, $key, '', ', ', '' );
				return is_string( $terms ) ? $terms : '';

			default:
				return '';
		}
	}

	/**
	 * Whether a meta key must never be read for a placeholder.
	 *
	 * The plugin's own keys are checked directly as well as through
	 * is_protected_meta(), because a site can filter that function.
	 *
	 * @param string $key The meta key.
	 * @return bool True when the key is denied.
	 */
	private static function is_denied( string $key ): bool {
		return in_array( $key, PostType::PROTECTED_META_KEYS, true ) || is_protected_meta( $key, 'post' );
	}

	/**
	 * Escapes a resolved value by its namespace's type.
	 *
	 * @param string $ns    The placeholder's namespace.
	 * @param string $value The resolved value.
	 * @return string The escaped value.
	 */
	private static function escape( string $ns, string $value ): string {
		switch ( self::NAMESPACES[ $ns ] ) {
			case 'url':
				return esc_url( $value );

			case 'html':
				return wp_kses_post( $value );

			default:
				return esc_attr( $value );
		}
	}

	/**
	 * Coerces a value to a string: a scalar is cast, anything else becomes `''`.
	 *
	 * @param mixed $value The value to coerce.
	 * @return string The value as a string.
	 */
	private static function to_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
