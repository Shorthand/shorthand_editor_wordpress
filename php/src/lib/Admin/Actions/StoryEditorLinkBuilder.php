<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryEditorLinkBuilder {

	public function build( ?int $post_id = null, ?string $story_id = null ): string {
		$params = array(
			'_wpnonce' => wp_create_nonce( 'shorthand_redirect' ),
		);

		if ( isset( $story_id ) ) {
			$params['story'] = $story_id;
		}

		if ( isset( $post_id ) ) {
			$params['post'] = $post_id;
		}

		return add_query_arg(
			$params,
			admin_url( 'admin-post.php?action=shorthand_editor' )
		);
	}
}
