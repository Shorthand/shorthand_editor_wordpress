<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Plugin\Dependencies;

use WP_Error;

class Cron {

	/**
	 * @var \Shorthand\Plugin\Dependencies
	 */
	private $dependencies;

	public function __construct( Dependencies $dependencies ) {
		$this->dependencies = $dependencies;
	}

	public function init() {
		$loader = new Loader();
		$loader->add_action( 'shorthand_pull_story_cron', $this, 'pull_story_cron', 10, 1 );
		if ( defined( 'THESHED_FIX_CRON_URL' ) && THESHED_FIX_CRON_URL ) {
			$loader->add_filter( 'site_url', $this, 'site_url_filter', 10, 4 );
		}
		$loader->register();
	}

	public function site_url_filter( $url, $path, $scheme, $blog_id ) {
		if ( $path === 'wp-cron.php' ) {
			$post_api = $this->dependencies->get_post_api();
			$url      = $post_api->fix_api_url( $url );
		}
		return $url;
	}

	/**
	 * Starts a background operation to pull the Shorthand story for a given post
	 *
	 * @param mixed $post_id
	 * @return void
	 */
	public function schedule_pull_story( int $post_id ) {
		$post_api = $this->dependencies->get_post_api();
		$args     = $post_api->pull_story_begin( $post_id );

		if ( ! is_wp_error( $args ) ) {
			$args = wp_schedule_single_event( time(), 'shorthand_pull_story_cron', array( wp_json_encode( $args ) ) );
		}

		if ( is_wp_error( $args ) ) {
			$post_api->set_story_update_error( $post_id, $args );
		}

		return $args;
	}

	public function pull_story_cron( string $args_json ): void {
		$args = StoryUpdateTask::from_json( $args_json );
		if ( ! $args ) {
			return;
		}

		$post_api = $this->dependencies->get_post_api();
		$result   = $post_api->pull_story_cron( $args );

		if ( $result === null ) {
			return;
		}

		if ( $result->get_error_code() === 'retry' ) {
			$delay  = $result->get_error_data( 'retry' );
			$result = wp_schedule_single_event( time() + $delay, 'shorthand_pull_story_cron', array( wp_json_encode( $args ) ) );
		}

		if ( is_wp_error( $result ) ) {
			$post_api->pull_story_failed( $args, $result );
		}
	}
}
