<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks up local WordPress posts by the Shorthand story they are linked to.
 */
class StoryLocalLookup {

	/**
	 * The post type to search within.
	 *
	 * @readonly
	 * @var string
	 */
	private $post_type;

	public function __construct( string $post_type ) {
		$this->post_type = $post_type;
	}

	/**
	 * Find the ID of a local post, in any status, whose `story_id` meta
	 * matches the given Shorthand story ID.
	 *
	 * @param string $story_id Shorthand story ID.
	 * @return int|null The post ID, or null if no matching post exists.
	 */
	public function find_post_id_by_story_id( string $story_id ): ?int {
		$posts = get_posts(
			array(
				'post_type'      => $this->post_type,
				'post_status'    => 'any',
				'meta_key'       => 'story_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $story_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		if ( empty( $posts ) ) {
			return null;
		}

		return (int) $posts[0];
	}
}
