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
		// $loader->add_filter('theme_shorthand_story_templates', $this, 'theme_templates', 10, 4);
		$loader->add_action( 'wp_head', $this, 'single_head' );

		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_scripts' );

		$loader->register();
	}

	public function single_template( $template ) {
		global $post;

		if ( $post->post_type === $this->post_type ) {
			// Check if a custom page template is selected.
			$custom_template = get_post_meta( $post->ID, '_wp_page_template', true );

			// If custom template is set and not 'default', try to locate it.
			if ( $custom_template && 'default' !== $custom_template ) {
				$located = locate_template( $custom_template );
				if ( $located ) {
					return $located;
				}
			}

			// If no custom template or 'default' is selected, use the plugin's template logic.

			// Look for themes or overrides.

			$theme_template = locate_template(
				array(
					'single-shorthand_story.php',
					'templates/single-shorthand_story.php',
					'template-parts/single-shorthand_story.php',
					'single-shorthand-story.php',
					'templates/single-shorthand-story.php',
					'template-parts/single-shorthand-story.php',
				)
			);

			if ( $theme_template ) {
				return $theme_template;
			}

			// Fallback to plugin template if theme template doesn't exist.
			$plugin_template = $this->version->get_plugin_path( 'templates/single-shorthand-story.php' );
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}

	/**
	 * Prints meta tags from story head content.
	 *
	 * Scripts and stylesheets are enqueued separately in enqueue_scripts().
	 */
	public function single_head() {
		global $post;

		if ( ! is_single() || $post->post_type !== $this->post_type ) {
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
