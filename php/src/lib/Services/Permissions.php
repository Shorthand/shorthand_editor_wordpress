<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Permissions {

	public function can_manage_shorthand() {
		return current_user_can( 'activate_plugins' );
	}

	public function can_pull_story( int $post_id ): bool {
		return current_user_can( 'publish_post', $post_id );
	}

	public function can_preview_story( int $post_id ) {
		return current_user_can( 'read_post', $post_id );
	}
}
