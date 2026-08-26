<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Core\Version;
use Shorthand\Plugin\Dependencies;

/**
 * Serves live Shorthand content when an editor previews a story on the front end.
 *
 * WordPress's own Preview button points at the story permalink, so the request
 * runs through the normal theme hierarchy and picks up the site header and
 * footer. Story content lives in the Shorthand API rather than in saved post
 * meta, so the three story meta keys are swapped for live values while the
 * preview is being served.
 */
class LivePreview {

	/**
	 * @var string
	 */
	private $post_type;

	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	/**
	 * @var \Shorthand\Plugin\Dependencies
	 */
	private $dependencies;

	/**
	 * The story being previewed, once the request has been recognised.
	 *
	 * @var int|null
	 */
	private $post_id;

	/**
	 * @var \Shorthand\Services\StoryPreview|null
	 */
	private $content;

	/**
	 * @var bool
	 */
	private $fetched = false;

	public function __construct(
		string $post_type,
		AuthStateManager $auth_state_manager,
		Version $version,
		Dependencies $dependencies
	) {
		$this->post_type          = $post_type;
		$this->auth_state_manager = $auth_state_manager;
		$this->version            = $version;
		$this->dependencies       = $dependencies;
	}

	public function init(): void {
		$loader = new Loader();
		$loader->add_action( 'template_redirect', $this, 'resolve' );
		$loader->register();
	}

	/**
	 * Recognises a live preview request and, when it is one, takes over the
	 * story meta for the rest of the request.
	 */
	public function resolve(): void {
		$post_id = $this->recognise();
		if ( null === $post_id ) {
			return;
		}

		$this->post_id = $post_id;

		/*
		 * Both calls belong inside the capability check in recognise(). WordPress
		 * sets is_preview() from the `preview` query var alone, so leaving these
		 * outside it would let any visitor bypass the page cache on every story
		 * URL by appending `?preview=true`.
		 */
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$loader = new Loader();
		/* Later than core's own preview meta filters, which run at 10. */
		$loader->add_filter( 'get_post_metadata', $this, 'filter_meta', 11, 4 );
		$loader->add_action( 'theshed_before_story', $this, 'render_notice' );
		$loader->register();
	}

	/**
	 * @return int|null The story being previewed, or null when this is an
	 *                  ordinary request or the viewer may not preview.
	 */
	private function recognise(): ?int {
		if ( ! is_preview() || ! is_singular( $this->post_type ) ) {
			return null;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! $post_id ) {
			return null;
		}

		/*
		 * The security boundary, not a formality: is_preview() is true for an
		 * anonymous visitor on any URL carrying `?preview=true`.
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		if ( ! $this->auth_state_manager->is_connected() ) {
			return null;
		}

		return $post_id;
	}

	/**
	 * Swaps saved story meta for the live version held by Shorthand.
	 *
	 * The three story keys are ours exclusively. Everything else, including the
	 * whole-post form of get_post_meta() that passes an empty key, is left to
	 * the normal lookup.
	 *
	 * @param mixed  $value     Short-circuit value, null until something sets it.
	 * @param int    $object_id Post the meta was requested for.
	 * @param string $meta_key  Meta key, or '' when every key was requested.
	 * @param bool   $single    Whether a scalar was asked for.
	 * @return mixed
	 */
	public function filter_meta( $value, $object_id, $meta_key, $single ) {
		if ( null === $this->post_id || (int) $object_id !== $this->post_id ) {
			return $value;
		}

		$content = $this->get_content();
		if ( null === $content ) {
			return $value;
		}

		switch ( $meta_key ) {
			case 'story_body':
				$live = $content->get_body();
				break;
			case 'story_head':
				$live = $content->get_head();
				break;
			case 'story_version':
				$live = $content->get_content_version();
				break;
			default:
				return $value;
		}

		return $single ? $live : array( $live );
	}

	/**
	 * Tells the editor when they are looking at saved content rather than the
	 * live story. Only reachable on a recognised preview, so the viewer has
	 * already been checked for `edit_post`.
	 */
	public function render_notice(): void {
		if ( null === $this->post_id || null !== $this->get_content() ) {
			return;
		}

		include $this->version->get_plugin_path( 'assets/partials/live-preview-notice.php' );
	}

	/**
	 * Fetches the live story once per request, whether or not it succeeds.
	 */
	private function get_content(): ?StoryPreview {
		if ( ! $this->fetched ) {
			$this->fetched = true;
			$this->content = $this->dependencies->get_post_api()->get_preview_content( $this->post_id );
		}

		return $this->content;
	}
}
