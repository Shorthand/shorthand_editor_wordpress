<?php

namespace Shorthand\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Core\Loader;
use Shorthand\Services\Options;
use Shorthand\Services\StoryKses;

class Templates {
	/**
	 * @readonly
	 * @var string
	 */
	public $post_type;
	/**
	 * @readonly
	 * @var \Shorthand\Services\Options
	 */
	public $options;
	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	public function __construct( string $post_type, Options $options, Version $version ) {
		$this->options   = $options;
		$this->version   = $version;
		$this->post_type = $post_type;
	}

	public function init() {
		$loader = new Loader();
		$loader->add_action( 'init', $this, 'register_templates' );
		$loader->register();
	}

	public function register_templates() {
		$loader = new Loader();

		$loader->add_filter( 'single_template', $this, 'single_template' );
		$loader->add_filter( 'template_include', $this, 'front_page_template', 99 );
		$loader->add_action( 'wp_head', $this, 'single_head' );

		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_scripts' );

		$loader->register();
	}

	public function single_template( $template ) {
		global $post;

		if ( $post->post_type !== $this->post_type ) {
			return $template;
		}

		$story_template = $this->resolve_story_template( (int) $post->ID );

		return '' !== $story_template ? $story_template : $template;
	}

	/**
	 * Finds the template that should render a story.
	 *
	 * Resolution order: the story's own page template, a theme override, then
	 * the template shipped with the plugin.
	 *
	 * @param int $post_id The story to resolve a template for.
	 * @return string The template path, or an empty string when none is found.
	 */
	private function resolve_story_template( int $post_id ): string {
		$custom_template = get_post_meta( $post_id, '_wp_page_template', true );

		if ( $custom_template && 'default' !== $custom_template ) {
			$located = locate_template( $custom_template );
			if ( $located ) {
				return $located;
			}
		}

		$theme_template = locate_template(
			array(
				'single-tse-story.php',
				'templates/single-tse-story.php',
				'template-parts/single-tse-story.php',
				'single-tse_story.php',
				'templates/single-tse_story.php',
				'template-parts/single-tse_story.php',
			)
		);

		if ( $theme_template ) {
			return $theme_template;
		}

		$plugin_template = $this->version->get_plugin_path( 'templates/single-tse-story.php' );

		return file_exists( $plugin_template ) ? $plugin_template : '';
	}

	/**
	 * Renders a block template part and returns its markup.
	 *
	 * Block themes register their block styles and scripts as the blocks render,
	 * so a template part must be rendered before `<head>` is written for those
	 * assets to be picked up by `wp_head()`. Returning the markup instead of
	 * echoing it lets a template render the part up front and print it later,
	 * which is how core's template canvas handles the same problem.
	 *
	 * @param string $part The template part area, such as 'header' or 'footer'.
	 * @return string The rendered markup, or an empty string when unavailable.
	 */
	public static function render_block_template_part( string $part ): string {
		if ( ! function_exists( 'block_template_part' ) ) {
			return '';
		}

		ob_start();
		block_template_part( $part );

		return (string) ob_get_clean();
	}

	/**
	 * Uses the story template when a story is the static front page.
	 *
	 * A front page request is a page request, so core resolves it through
	 * get_page_template() and the `single_template` filter never fires. Left
	 * alone, a story set as the home page renders through the theme's page
	 * template, which prints the title and no story body.
	 *
	 * @param string $template The resolved template path.
	 * @return string
	 */
	public function front_page_template( $template ) {
		/*
		 * `page_on_front` keeps its value after the site switches back to
		 * showing latest posts, and is_front_page() is true for the blog index,
		 * so the stale option would otherwise capture the blog index.
		 */
		if ( ! is_front_page() || 'page' !== get_option( 'show_on_front' ) ) {
			return $template;
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( ! $front_page_id || get_post_type( $front_page_id ) !== $this->post_type ) {
			return $template;
		}

		$story_template = $this->resolve_story_template( $front_page_id );

		return '' !== $story_template ? $story_template : $template;
	}

	/**
	 * Prints meta tags from story head content.
	 *
	 * Scripts and stylesheets are enqueued separately in enqueue_scripts().
	 */
	public function single_head() {
		if ( ! is_singular( $this->post_type ) ) {
			return;
		}

		$story_head = get_post_meta( get_post()->ID, 'story_head', true );
		if ( empty( $story_head ) ) {
			return;
		}

		// Echo meta tags with escaped attributes - scripts and styles are handled in enqueue_scripts().
		StoryKses::echo_meta_tags( $story_head );
	}

	/**
	 * Enqueues scripts and stylesheets for story pages.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_singular( $this->post_type ) ) {
			return;
		}

		$user_css = $this->options->get_post_css();
		wp_register_style( 'theshed-user-style', false, array(), md5( $user_css ) );
		wp_enqueue_style( 'theshed-user-style' );
		wp_add_inline_style( 'theshed-user-style', wp_kses( $user_css, array() ) );

		// Register handle for story scripts extracted during KSES filtering.
		// This is an inline script and doesn't have a version.
		wp_register_script( StoryKses::SCRIPT_HANDLE, false, array(), null, true ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */

		$post_id       = get_post()->ID;
		$story_head    = get_post_meta( $post_id, 'story_head', true );
		$story_version = get_post_meta( $post_id, 'story_version', true );
		$story_version = is_numeric( $story_version ) ? (int) $story_version : null;

		if ( ! empty( $story_head ) ) {
			StoryKses::enqueue_head_assets( $story_head, false, $story_version );
		}
	}
}
