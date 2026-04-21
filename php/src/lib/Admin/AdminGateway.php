<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminGateway {

	/**
	 * The slug of the plugin's settings page, used to build its admin URL.
	 *
	 * @readonly
	 * @var string
	 */
	private $settings_page_slug;

	public function __construct( string $settings_page_slug ) {
		$this->settings_page_slug = $settings_page_slug;
	}

	public function get_post( int $post_id ) {
		return get_post( $post_id );
	}

	public function sync_post_title( $post, string $story_title ): void {
		$title = sanitize_post_field( 'post_title', $story_title, $post->ID, 'db' );
		if ( $title && $post->post_title !== $title ) {
			wp_update_post(
				array(
					'ID'         => $post->ID,
					'post_title' => $title,
				)
			);
		}
	}

	public function get_edit_post_link( int $post_id ): ?string {
		return get_edit_post_link( $post_id, 'raw' );
	}

	public function get_all_stories_url( string $post_type ): string {
		return admin_url( "edit.php?post_type={$post_type}" );
	}

	public function get_settings_page_url(): string {
		return admin_url( 'options-general.php?page=' . rawurlencode( $this->settings_page_slug ) );
	}
}
