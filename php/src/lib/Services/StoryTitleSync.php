<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;

/**
 * Keeps a story's title the same in WordPress and in Shorthand.
 *
 * The WordPress title always saves. When the push to Shorthand cannot be
 * made, the title is held in `story_title_pending` post meta, shown in the
 * editor as a known divergence, and pushed again once the connection returns.
 */
class StoryTitleSync {

	const META_KEY = 'story_title_pending';

	const SWEEP_LIMIT = 100;

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	/**
	 * @var string
	 */
	private $post_type;

	public function __construct( Shorthand $shorthand, AuthStateManager $auth_state_manager, string $post_type ) {
		$this->shorthand          = $shorthand;
		$this->auth_state_manager = $auth_state_manager;
		$this->post_type          = $post_type;
	}

	public function init( Loader $loader ): void {
		$loader->add_action( 'shorthand_auth_state_changed', $this, 'auth_state_changed', 10, 1 );
	}

	/**
	 * Push a title to Shorthand, or hold it until the connection returns.
	 */
	public function push( int $post_id, string $story_id, string $title ): void {
		if ( ! $this->auth_state_manager->is_connected() ) {
			update_post_meta( $post_id, self::META_KEY, $title );
			return;
		}

		$error = $this->shorthand->set_story_title( $story_id, $title );
		if ( null !== $error ) {
			update_post_meta( $post_id, self::META_KEY, $title );
			return;
		}

		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * The title WordPress holds that Shorthand has not yet received.
	 */
	public function get_pending( int $post_id ): ?string {
		$title = get_post_meta( $post_id, self::META_KEY, true );
		return is_string( $title ) && '' !== $title ? $title : null;
	}

	public function auth_state_changed( string $state ): void {
		if ( AuthStateManager::STATE_CONNECTED === $state ) {
			$this->sweep();
		}
	}

	/**
	 * Push every held title. Runs once when the connection returns.
	 */
	public function sweep(): void {
		$post_ids = get_posts(
			array(
				'post_type'    => $this->post_type,
				'post_status'  => 'any',
				'numberposts'  => self::SWEEP_LIMIT,
				'fields'       => 'ids',
				'meta_key'     => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => '!=',
				'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post_id  = (int) $post_id;
			$story_id = get_post_meta( $post_id, 'story_id', true );
			if ( ! $story_id ) {
				delete_post_meta( $post_id, self::META_KEY );
				continue;
			}

			$this->push( $post_id, (string) $story_id, (string) get_post_field( 'post_title', $post_id, 'raw' ) );
		}
	}
}
