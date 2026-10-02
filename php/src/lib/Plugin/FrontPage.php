<?php

namespace Shorthand\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;

/**
 * Makes a story usable as the site's static front page.
 *
 * Core treats a static front page as a page throughout: the Reading settings
 * offer pages only, the main query is constrained to the `page` post type, and
 * the template comes from get_page_template(). Each of the three needs its own
 * correction, and all three are here.
 */
class FrontPage {
	/**
	 * @readonly
	 * @var string
	 */
	public $post_type;
	/**
	 * @readonly
	 * @var \Shorthand\Plugin\Templates
	 */
	public $templates;

	public function __construct( string $post_type, Templates $templates ) {
		$this->post_type = $post_type;
		$this->templates = $templates;
	}

	public function init() {
		$loader = new Loader();

		$loader->add_filter( 'get_pages', $this, 'offer_stories_as_front_page', 10, 2 );
		$loader->add_action( 'pre_get_posts', $this, 'find_front_page_story' );
		$loader->add_filter( 'template_include', $this, 'front_page_template', 99 );

		$loader->register();
	}

	/**
	 * Offers published stories in the Reading settings home page control.
	 *
	 * The control is built by wp_dropdown_pages(), which takes its list from
	 * get_pages() and so only ever offers pages. Adding stories to that list
	 * also restores the control on a site with no pages at all, where
	 * wp_dropdown_pages() prints nothing for an empty list.
	 *
	 * @param mixed                $pages       Posts the caller will render.
	 * @param array<string, mixed> $parsed_args Arguments get_pages() was called with.
	 * @return mixed
	 */
	public function offer_stories_as_front_page( $pages, $parsed_args ) {
		if ( ! isset( $parsed_args['name'] ) || 'page_on_front' !== $parsed_args['name'] ) {
			return $pages;
		}

		$stories = get_posts(
			array(
				'post_type'   => $this->post_type,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		return array_merge( is_array( $pages ) ? $pages : array(), $stories );
	}

	/**
	 * Lets the main query find a story serving as the static front page.
	 *
	 * Core resolves a static front page as a page: WP_Query::parse_query() sets
	 * `is_page` and puts the front page in `page_id`, and WP_Query::get_posts()
	 * then constrains the query to `post_type = 'page'` because nothing asked
	 * for another type. A story matches nothing, so the query comes back empty,
	 * is_front_page() has no queried object to test and WP::handle_404() serves
	 * a 404. Naming the post type here takes the earlier branch in get_posts().
	 *
	 * `pre_get_posts` is early enough: it fires in get_posts(), before the
	 * clause is built.
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public function find_front_page_story( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$front_page_id = $this->front_page_story_id();
		if ( ! $front_page_id || (int) $query->get( 'page_id' ) !== $front_page_id ) {
			return;
		}

		$query->set( 'post_type', $this->post_type );
	}

	/**
	 * Uses the story template when a story is the static front page.
	 *
	 * A front page request is a page request, so core resolves it through
	 * get_page_template() and the `single_template` filter never fires. Once
	 * find_front_page_story() lets the query find the story, that resolves to
	 * the theme's page template, which prints the title and no story body.
	 *
	 * `template_include` is the only hook every route passes through, and it
	 * runs after core has settled on a template.
	 *
	 * @param string $template The resolved template path.
	 * @return string
	 */
	public function front_page_template( $template ) {
		if ( ! is_front_page() ) {
			return $template;
		}

		$front_page_id = $this->front_page_story_id();
		if ( ! $front_page_id ) {
			return $template;
		}

		$story_template = $this->templates->resolve_story_template( $front_page_id );

		return '' !== $story_template ? $story_template : $template;
	}

	/**
	 * The story serving as the static front page, or 0 when there is none.
	 *
	 * `page_on_front` keeps its value after a site switches back to showing
	 * latest posts, and is_front_page() is true for the blog index in that
	 * mode, so `show_on_front` has to be tested as well. Without it the stale
	 * option would capture the blog index.
	 */
	private function front_page_story_id(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( ! $front_page_id || get_post_type( $front_page_id ) !== $this->post_type ) {
			return 0;
		}

		return $front_page_id;
	}
}
