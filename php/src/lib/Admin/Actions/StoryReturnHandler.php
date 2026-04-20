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
		$this->post_api      = $post_api;
		$this->shorthand     = $shorthand;
		$this->admin_gateway = $admin_gateway;
		$this->link_builder  = $link_builder;
		$this->post_type     = $post_type;
	}

	public function handle( ?int $post_id, ?string $story_id, ?string $error, ?string $target, ?string $create_type ): ActionResult {
		if ( $error ) {
			return $this->build_navigation_error_result( $post_id, $story_id, $error, $create_type );
		}

		if ( $create_type && $create_type !== $this->post_type ) {
			return ActionResult::error(
				__( 'Shorthand returned a different content type than the one requested, so no WordPress post was created. No content was lost — the story is still available in Shorthand.', 'the-shorthand-editor' ),
				__( "Couldn't add story to WordPress", 'the-shorthand-editor' ),
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

	/**
	 * Build a user-friendly error result for a failed return from Shorthand.
	 *
	 * Chooses messaging based on whether the user was creating a story,
	 * editing an existing post, or returning without a known post, and
	 * offers a "Reopen story in Shorthand" primary action when a story
	 * identifier is known, falling back to a return-to-WordPress link.
	 *
	 * @param int|null    $post_id     WordPress post ID, if the return is tied to one.
	 * @param string|null $story_id    Shorthand story identifier, if known.
	 * @param string      $error       Raw error code or message reported by Shorthand.
	 * @param string|null $create_type Post type requested during story creation, if applicable.
	 */
	private function build_navigation_error_result( ?int $post_id, ?string $story_id, string $error, ?string $create_type ): ActionResult {
		$all_stories_url  = $this->admin_gateway->get_all_stories_url( $this->post_type );
		$edit_post_link   = $post_id ? $this->admin_gateway->get_edit_post_link( $post_id ) : null;
		$context_link_url = $edit_post_link ? $edit_post_link : $all_stories_url;

		$title = __( "Couldn't return from Shorthand", 'the-shorthand-editor' );

		if ( $create_type ) {
			$message = __(
				'Shorthand reported a problem while creating your story. Nothing has been lost — if the story was created, you can reopen it from Shorthand.',
				'the-shorthand-editor'
			);
		} elseif ( $post_id ) {
			$message = __(
				'Shorthand reported a problem while returning to WordPress. Your story is safe and any saved changes are still in Shorthand. You can try opening it again, or go back to this post in WordPress.',
				'the-shorthand-editor'
			);
		} else {
			$message = __(
				'Shorthand reported a problem while returning to WordPress. Your story is safe in Shorthand.',
				'the-shorthand-editor'
			);
		}

		$message .= ' ' . sprintf(
			/* translators: %s: short error code or message returned by Shorthand */
			__( 'Details from Shorthand: %s. If this keeps happening, share this reference with Shorthand support.', 'the-shorthand-editor' ),
			$error
		);

		$primary_link_url  = null;
		$primary_link_text = null;
		if ( $story_id ) {
			$primary_link_url  = $this->link_builder->build( $post_id, $story_id );
			$primary_link_text = __( 'Reopen story in Shorthand', 'the-shorthand-editor' );
		}

		if ( ! $primary_link_url ) {
			return ActionResult::error(
				$message,
				$title,
				$context_link_url,
				$post_id ? __( 'Return to story', 'the-shorthand-editor' ) : __( 'Return to all stories', 'the-shorthand-editor' )
			);
		}

		$secondary_link_url  = $context_link_url;
		$secondary_link_text = $post_id ? __( 'Return to story', 'the-shorthand-editor' ) : __( 'Return to all stories', 'the-shorthand-editor' );

		return ActionResult::error(
			$message,
			$title,
			$primary_link_url,
			$primary_link_text,
			$secondary_link_url,
			$secondary_link_text
		);
	}
}
