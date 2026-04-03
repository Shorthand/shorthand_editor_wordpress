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
		$loader->add_filter( 'wp_allowed_hosts', $this, 'whitelist_shorthand_domains', 10, 1 );
		$loader->register();
	}

	/**
	 * Whitelists shorthand.com domains for use in content.
	 *
	 * @param array $allowed_hosts Array of allowed hostnames.
	 * @return array Modified array of allowed hostnames.
	 */
	public function whitelist_shorthand_domains( array $allowed_hosts ): array {
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

		( new StoryAssetEnqueuer() )->enqueue_story_scripts( self::$scripts, $story_version );

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
				$tags[ $tag ] = array_merge( $attrs, $extra_attrs, $permissive_attrs );
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

		$global_attrs = $allowed_html['div'] ?? array();

		foreach ( ( new StoryAssetParser() )->find_shorthand_tags( $content ) as $tag ) {
			if ( ! isset( $allowed_html[ $tag ] ) ) {
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

		$result        = ( new StoryAssetParser() )->extract_script_tags( $content );
		self::$scripts = array_merge( self::$scripts, $result['scripts'] );

		return $result['content'];
	}

	/**
	 * Extracts scripts and styles from content, enqueues them via WordPress APIs,
	 * and echoes the content with those tags removed (no other HTML filtering).
	 *
	 * Unlike wp_kses(), this does not filter HTML tags, attributes, or CSS properties.
	 * Use only with trusted content sources.
	 *
	 * @param string   $content       The HTML content.
	 * @param int|null $story_version Story version for cache busting.
	 */
	public static function echo_extract_and_enqueue_assets( string $content, ?int $story_version = null ): void {
		$parser          = new StoryAssetParser();
		$script_result   = $parser->extract_script_tags( $content );
		$style_result    = $parser->extract_style_tags( $script_result['content'] );
		$asset_enqueuer  = new StoryAssetEnqueuer();
		self::$scripts   = array_merge( self::$scripts, $script_result['scripts'] );
		$content         = $style_result['content'];

		$asset_enqueuer->enqueue_inline_styles( $style_result['styles'], 'theshed-story-body-style-' );

		self::enqueue_story_scripts( $story_version );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted Shorthand content.
		echo $content;
	}

	/**
	 * Filters content through wp_kses with story-specific allowances, extracts scripts,
	 * enqueues them via WordPress APIs, and echoes the filtered content.
	 *
	 * This applies wp_kses() filtering with extended tag/attribute/CSS allowances
	 * configured via StoryKses::enable(). Use echo_extract_and_enqueue_assets() instead
	 * if you need to preserve all HTML without any filtering.
	 *
	 * @param string   $content       The HTML content.
	 * @param int|null $story_version Story version for cache busting.
	 */
	public static function echo_extract_and_enqueue_assets_kses( string $content, ?int $story_version = null ): void {
		// Pass 'post' context string rather than pre-resolved array, so dynamic
		// sh-* tag filters added by pre_kses are included when KSES resolves tags.
		$content = wp_kses(
			$content,
			'post',
			self::get_allowed_protocols()
		);

		self::enqueue_story_scripts( $story_version );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered by wp_kses above.
		echo $content;
	}

	/**
	 * Echoes meta tags from head content with escaped attributes.
	 *
	 * @param string $head_content The story head HTML content.
	 */
	public static function echo_meta_tags( string $head_content ): void {
		foreach ( ( new StoryAssetParser() )->extract_meta_tags( $head_content ) as $attrs ) {
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
	 * Enqueues scripts and stylesheets from story head content.
	 *
	 * Assets are enqueued in the same order they appear in the original content.
	 *
	 * @param string   $head_content   The story head HTML content.
	 * @param bool     $in_footer      Whether to load scripts in footer. Default false for head content.
	 * @param int|null $story_version  Story version for cache busting. Default null.
	 */
	public static function enqueue_head_assets( string $head_content, bool $in_footer = false, ?int $story_version = null ): void {
		( new StoryAssetEnqueuer() )->enqueue_head_assets(
			( new StoryAssetParser() )->parse_head_assets( $head_content ),
			$in_footer,
			$story_version
		);
	}
}
