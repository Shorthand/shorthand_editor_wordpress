<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;

/**
 * Configures wp_kses to allow Shorthand story HTML content.
 *
 * Adds `picture` and `source` tags with global attributes, and permits
 * all CSS properties in inline styles when enabled.
 */
class StoryKses {

	/**
	 * @var bool Whether story KSES filtering is currently enabled.
	 */
	private static $enabled = false;

	/**
	 * @var string[] Script tags extracted during filtering.
	 */
	private static $scripts = array();

	/**
	 * Script handle for story inline scripts.
	 */
	public const SCRIPT_HANDLE = 'theshed-story-scripts';

	/**
	 * Registers the KSES filters.
	 */
	public function init(): void {
		$loader = new Loader();
		$loader->add_filter( 'wp_kses_allowed_html', $this, 'add_story_tags', 10, 2 );
		$loader->add_filter( 'pre_kses', $this, 'allow_sh_tags', 10, 2 );
		$loader->add_filter( 'pre_kses', $this, 'extract_scripts', 10, 2 );
		$loader->add_filter('wp_allowed_hosts', $this, 'whitelist_shorthand_domains', 10, 1);
		$loader->register();
	}

	/**
	 * Whitelists shorthand.com domains for use in content.
	 *
	 * @param array $allowed_hosts Array of allowed hostnames.
	 * @return array Modified array of allowed hostnames.
	 */
	public function whitelist_shorthand_domains(array $allowed_hosts): array {
		$allowed_hosts[] = 'media.shorthand.com';
		$allowed_hosts[] = 'iframely.shorthand.com';
		$allowed_hosts[] = 'analytics.shorthand.com';
		return $allowed_hosts;
	}

	/**
	 * Enables permissive CSS filtering for story content.
	 *
	 * Call this before using wp_kses() on story HTML.
	 */
	public static function enable(): void {
		if ( self::$enabled ) {
			return;
		}
		self::$enabled = true;
		add_filter( 'safe_style_css', '__return_empty_array' );
		add_filter( 'safecss_filter_attr_allow_css', '__return_true' );
	}

	/**
	 * Disables permissive CSS filtering.
	 *
	 * Call this after processing story content to restore default behavior.
	 */
	public static function disable(): void {
		if ( ! self::$enabled ) {
			return;
		}
		self::$enabled = false;
		self::$scripts = array();
		remove_filter( 'safe_style_css', '__return_empty_array' );
		remove_filter( 'safecss_filter_attr_allow_css', '__return_true' );
	}

	/**
	 * Returns allowed protocols including 'data' for inline images.
	 *
	 * @return string[] Array of allowed URL protocols.
	 */
	public static function get_allowed_protocols(): array {
		$protocols   = wp_allowed_protocols();
		$protocols[] = 'data';
		return $protocols;
	}

	/**
	 * Enqueues scripts extracted during KSES filtering.
	 *
	 * Call this after wp_kses() to enqueue any scripts that were extracted
	 * from the story content. External scripts are enqueued separately,
	 * inline scripts are attached to the story scripts handle.
	 *
	 * @param int|null $story_version Story version for cache busting. Default null.
	 */
	public static function enqueue_story_scripts( ?int $story_version = null ): void {
		if ( empty( self::$scripts ) ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );

		foreach ( self::$scripts as $script ) {
			if ( preg_match( '/src=["\']([^"\']+)["\']/', $script, $src_match ) ) {
				// External script - enqueue it.
				$handle = 'theshed-story-' . md5( $src_match[1] );
				wp_enqueue_script( $handle, $src_match[1], array(), $story_version, true );
			} elseif ( preg_match( '/<script\b[^>]*>(.*?)<\/script>/is', $script, $content_match ) ) {
				// Inline script - decode HTML entities and add to registered handle.
				// This script does not have a version.
				$script_content = html_entity_decode( $content_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				wp_add_inline_script( self::SCRIPT_HANDLE, $script_content ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
			}
		}

		self::$scripts = array();
	}

	/**
	 * Adds picture and source tags to the allowed HTML list.
	 *
	 * Only active when story KSES filtering is enabled.
	 *
	 * @param array[]|string $tags    Allowed HTML tags and attributes.
	 * @param string         $context The context (e.g., 'post').
	 * @return array[] Modified allowed HTML tags.
	 */
	public function add_story_tags( $tags, $context ): array {
		if ( ! self::$enabled || 'post' !== $context || ! is_array( $tags ) ) {
			return $tags;
		}

		// Use global attributes from an existing tag.
		$global_attrs = $tags['div'] ?? array();

		// Add attributes not in WordPress's default global attributes.
		$extra_attrs = array(
			'aria-modal'  => true,
			'aria-atomic' => true,
			'slot'        => true,
			'tabindex'    => true,
		);

		// Permissive attributes for embedded content tags.
		// wp_kses doesn't support "allow all" so we list comprehensive attributes.
		$permissive_attrs = array( 'data-*' => true );

		$tags['iframe'] = array_merge(
			$global_attrs,
			$permissive_attrs,
			array(
				'src'             => true,
				'srcdoc'          => true,
				'name'            => true,
				'width'           => true,
				'height'          => true,
				'frameborder'     => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'sandbox'         => true,
			)
		);

		$tags['form'] = array_merge(
			$global_attrs,
			$permissive_attrs,
			array(
				'action'         => true,
				'method'         => true,
				'enctype'        => true,
				'accept'         => true,
				'accept-charset' => true,
				'autocomplete'   => true,
				'novalidate'     => true,
				'target'         => true,
				'name'           => true,
				'rel'            => true,
			)
		);

		$tags['input'] = array_merge(
			$global_attrs,
			$permissive_attrs,
			array(
				'type'           => true,
				'name'           => true,
				'value'          => true,
				'accept'         => true,
				'alt'            => true,
				'autocomplete'   => true,
				'autofocus'      => true,
				'capture'        => true,
				'checked'        => true,
				'dirname'        => true,
				'disabled'       => true,
				'form'           => true,
				'formaction'     => true,
				'formenctype'    => true,
				'formmethod'     => true,
				'formnovalidate' => true,
				'formtarget'     => true,
				'height'         => true,
				'list'           => true,
				'max'            => true,
				'maxlength'      => true,
				'min'            => true,
				'minlength'      => true,
				'multiple'       => true,
				'pattern'        => true,
				'placeholder'    => true,
				'readonly'       => true,
				'required'       => true,
				'size'           => true,
				'src'            => true,
				'step'           => true,
				'width'          => true,
			)
		);

		$tags['video'] = array_merge(
			$global_attrs,
			$permissive_attrs,
			array(
				'src'          => true,
				'width'        => true,
				'height'       => true,
				'autoplay'     => true,
				'controls'     => true,
				'controlslist' => true,
				'crossorigin'  => true,
				'loop'         => true,
				'muted'        => true,
				'playsinline'  => true,
				'poster'       => true,
				'preload'      => true,
			)
		);

		$tags['picture'] = $global_attrs;
		$tags['source']  = array_merge(
			$global_attrs,
			array(
				'srcset' => true,
				'sizes'  => true,
				'media'  => true,
				'type'   => true,
			)
		);

		// SVG elements and common child elements.
		$svg_attrs = array_merge(
			$global_attrs,
			$permissive_attrs,
			array(
				'xmlns'             => true,
				'viewbox'           => true,
				'width'             => true,
				'height'            => true,
				'fill'              => true,
				'stroke'            => true,
				'stroke-width'      => true,
				'stroke-linecap'    => true,
				'stroke-linejoin'   => true,
				'opacity'           => true,
				'transform'         => true,
				'clip-path'         => true,
				'clip-rule'         => true,
				'fill-rule'         => true,
				'fill-opacity'      => true,
				'stroke-opacity'    => true,
				'stroke-dasharray'  => true,
				'stroke-dashoffset' => true,
			)
		);

		$tags['svg'] = array_merge(
			$svg_attrs,
			array(
				'xmlns:xlink'         => true,
				'preserveaspectratio' => true,
				'role'                => true,
				'focusable'           => true,
				'aria-hidden'         => true,
				'aria-label'          => true,
				'aria-labelledby'     => true,
			)
		);

		$tags['path'] = array_merge(
			$svg_attrs,
			array(
				'd' => true,
			)
		);

		$tags['rect'] = array_merge(
			$svg_attrs,
			array(
				'x'  => true,
				'y'  => true,
				'rx' => true,
				'ry' => true,
			)
		);

		$tags['circle'] = array_merge(
			$svg_attrs,
			array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			)
		);

		$tags['use'] = array_merge(
			$svg_attrs,
			array(
				'href'       => true,
				'xlink:href' => true,
				'x'          => true,
				'y'          => true,
			)
		);

		// Add extra attributes to all existing allowed tags.
		foreach ( $tags as $tag => $attrs ) {
			if ( is_array( $attrs ) ) {
				$tags[ $tag ] = array_merge( $attrs, $extra_attrs );
			}
		}

		return $tags;
	}

	/**
	 * Dynamically adds sh-* web component tags to the allowed HTML list.
	 *
	 * KSES doesn't support wildcard patterns for tag names, so this filter
	 * scans the content for sh-* tags and adds them before processing.
	 *
	 * Only active when story KSES filtering is enabled.
	 *
	 * @param string       $content      Content to filter.
	 * @param array|string $allowed_html Allowed HTML configuration.
	 * @return string Unmodified content (tags are added via side effect).
	 */
	public function allow_sh_tags( string $content, $allowed_html ): string {
		if ( ! self::$enabled ) {
			return $content;
		}

		if ( ! is_array( $allowed_html ) ) {
			$allowed_html = wp_kses_allowed_html( $allowed_html );
		}

		// Find all sh-* tag names in the content.
		if ( preg_match_all( '/<(sh-[a-z0-9-]+)/i', $content, $matches ) ) {
			$global_attrs = $allowed_html['div'] ?? array();

			foreach ( array_unique( $matches[1] ) as $tag ) {
				$tag = strtolower( $tag );
				if ( ! isset( $allowed_html[ $tag ] ) ) {
					// Add a filter that will include this tag.
					add_filter(
						'wp_kses_allowed_html',
						function ( $tags, $context ) use ( $tag, $global_attrs ) {
							if ( self::$enabled && 'post' === $context && is_array( $tags ) ) {
								$tags[ $tag ] = $global_attrs;
							}
							return $tags;
						},
						11,
						2
					);
				}
			}
		}

		return $content;
	}

	/**
	 * Extracts and removes script tags from content before KSES processes it.
	 *
	 * Only active when story KSES filtering is enabled.
	 *
	 * @param string       $content      Content to filter.
	 * @param array|string $allowed_html Allowed HTML configuration.
	 * @return string Content with script tags removed.
	 */
	public function extract_scripts( string $content, $_allowed_html ): string {
		if ( ! self::$enabled ) {
			return $content;
		}

		if ( preg_match_all( '/<script\b[^>]*>.*?<\/script>/is', $content, $matches ) ) {
			self::$scripts = array_merge( self::$scripts, $matches[0] );
			$content       = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $content );
		}

		return $content;
	}

	/**
	 * Echoes meta tags from head content with escaped attributes.
	 *
	 * @param string $head_content The story head HTML content.
	 */
	public static function echo_meta_tags( string $head_content ): void {
		if ( ! preg_match_all( '/<meta\b([^>]*)>/is', $head_content, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $attrs_string ) {
			$attrs = self::parse_html_attributes( $attrs_string );
			if ( empty( $attrs ) ) {
				continue;
			}

			if ( isset( $attrs['charset'] ) ) {
				continue;
			}

			echo '<meta';
			foreach ( $attrs as $name => $value ) {
				echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
			}
			echo ">\n";
		}
	}

	/**
	 * Parses HTML attributes from a string.
	 *
	 * Handles both value attributes (name="value") and boolean attributes (defer, async).
	 *
	 * @param string $attrs_string The attributes portion of an HTML tag.
	 * @return array<string, string|true> Associative array of attribute name => value (or true for boolean attributes).
	 */
	private static function parse_html_attributes( string $attrs_string ): array {
		$attrs = array();

		// Match attribute="value", attribute='value', attribute=value, or boolean attributes.
		if ( preg_match_all( '/([a-z0-9_-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/is', $attrs_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( isset( $match[2] ) && $match[2] !== '' ) {
					// Double-quoted value.
					$attrs[ $name ] = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} elseif ( isset( $match[3] ) && $match[3] !== '' ) {
					// Single-quoted value.
					$attrs[ $name ] = html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} elseif ( isset( $match[4] ) && $match[4] !== '' ) {
					// Unquoted value.
					$attrs[ $name ] = html_entity_decode( $match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} else {
					// Boolean attribute (no value).
					$attrs[ $name ] = true;
				}
			}
		}

		return $attrs;
	}

	/**
	 * Enqueues scripts and stylesheets from story head content.
	 *
	 * Assets are enqueued in the same order they appear in the original content.
	 *
	 * @param string   $head_content   The story head HTML content.
	 * @param bool     $in_footer      Whether to load scripts in footer. Default false for head content.
	 * @param int|null $story_version  Story version for cache busting. Default null.
	 */
	public static function enqueue_head_assets( string $head_content, bool $in_footer = false, ?int $story_version = null ): void {
		// Match all link, style, and script tags in order with their positions.
		$pattern = '/<(link|style|script)\b([^>]*)>(.*?)<\/\1>|<(link)\b([^>]*)>/is';

		if ( ! preg_match_all( $pattern, $head_content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		$style_index  = 0;
		$script_index = 0;

		foreach ( $matches as $match ) {
			// For self-closing link tags, tag name is in group 4, attrs in group 5.
			// For paired tags, tag name is in group 1, attrs in group 2, content in group 3.
			if ( ! empty( $match[4][0] ) ) {
				$tag_name     = strtolower( $match[4][0] );
				$attrs_string = $match[5][0];
				$content      = '';
			} else {
				$tag_name     = strtolower( $match[1][0] );
				$attrs_string = $match[2][0];
				$content      = $match[3][0];
			}

			$attrs = self::parse_html_attributes( $attrs_string );

			if ( 'link' === $tag_name ) {
				// Check if it's a stylesheet link.
				$rel  = $attrs['rel'] ?? '';
				$href = $attrs['href'] ?? '';
				if ( 'stylesheet' === $rel && $href ) {
					$handle = 'theshed-story-style-' . $style_index;
					wp_enqueue_style( $handle, $href, array(), $story_version );
					++$style_index;
				}
			} elseif ( 'style' === $tag_name ) {
				$handle = 'theshed-story-inline-style-' . $style_index;
				// This inline style sheet does not have a version.
				wp_register_style( $handle, false, array(), null ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
				wp_enqueue_style( $handle );
				wp_add_inline_style( $handle, $content );
				++$style_index;
			} elseif ( 'script' === $tag_name ) {
				$script_args = array(
					'in_footer' => $in_footer,
				);
				if ( isset( $attrs['defer'] ) ) {
					$script_args['strategy'] = 'defer';
				}

				$src = $attrs['src'] ?? '';
				if ( $src ) {
					// External script.
					$handle = 'theshed-story-head-script-' . $script_index;
					wp_enqueue_script( $handle, $src, array(), $story_version, $script_args );
				} else {
					// Inline script.
					$handle  = 'theshed-story-head-inline-' . $script_index;
					$decoded = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					// This script does not have a version.
					wp_register_script( $handle, false, array(), null, $script_args ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */
					wp_enqueue_script( $handle );
					wp_add_inline_script( $handle, $decoded );
				}
				++$script_index;
			}
		}
	}
}
