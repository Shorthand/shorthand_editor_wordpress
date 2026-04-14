<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Admin\AdminGateway;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;

class StoryReturnHandler {

	/**
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	/**
	 * @var \Shorthand\Admin\AdminGateway
	 */
	private $admin_gateway;

	/**
	 * @var \Shorthand\Admin\Actions\StoryEditorLinkBuilder
	 */
	private $link_builder;

	/**
	 * @var string
	 */
	private $post_type;

	public function __construct( PostAPI $post_api, Shorthand $shorthand, AdminGateway $admin_gateway, StoryEditorLinkBuilder $link_builder, string $post_type ) {
		$this->post_api       = $post_api;
		$this->shorthand      = $shorthand;
		$this->admin_gateway  = $admin_gateway;
		$this->link_builder   = $link_builder;
		$this->post_type      = $post_type;
	}

	public function handle( ?int $post_id, ?string $story_id, ?string $error, ?string $target, ?string $create_type ): ActionResult {
		if ( $error ) {
			$link_url  = $post_id ? $this->admin_gateway->get_edit_post_link( $post_id ) : $this->admin_gateway->get_all_stories_url( $this->post_type );
			$link_text = $post_id ? __( 'Return to story', 'the-shorthand-editor' ) : __( 'Return to all stories', 'the-shorthand-editor' );

			return ActionResult::error(
				__( 'Something went wrong while returning from Shorthand. Your story has not been lost. Please contact Shorthand support if this problem continues.', 'the-shorthand-editor' ),
				__( 'Error', 'the-shorthand-editor' ),
				$link_url,
				$link_text
			);
		}

		if ( $create_type && $create_type !== $this->post_type ) {
			return ActionResult::error(
				__( 'An unexpected error occurred while creating the story. The post type received does not match what was expected.', 'the-shorthand-editor' ),
				__( 'Error', 'the-shorthand-editor' ),
				$this->admin_gateway->get_all_stories_url( $this->post_type ),
				__( 'Return to all stories', 'the-shorthand-editor' )
			);
		}

		if ( $create_type && $story_id ) {
			$post = $this->post_api->connect_story( $story_id, null );

			return ActionResult::redirect(
				$this->link_builder->build( isset( $post->ID ) ? (int) $post->ID : null, $story_id )
			);
		}

		$post = $post_id ? $this->admin_gateway->get_post( $post_id ) : null;

		if ( $story_id && $post ) {
			$title = $this->shorthand->get_story_title( $story_id );
			if ( is_string( $title ) && $title !== '' ) {
				$this->admin_gateway->sync_post_title( $post, $title );
			}
		}

		if ( ! $target && $post_id ) {
			$target = $this->admin_gateway->get_edit_post_link( $post_id );
		}

		if ( ! $target ) {
			$target = $this->admin_gateway->get_all_stories_url( $this->post_type );
		}

		return ActionResult::redirect( $target );
	}
}
