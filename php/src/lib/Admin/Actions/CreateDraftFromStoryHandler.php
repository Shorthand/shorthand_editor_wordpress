<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryAttachmentResolver;

/**
 * Creates a draft WordPress post for a Shorthand story that is not yet
 * attached to any post.
 *
 * Re-verifies unattachment immediately before creating the post, guarding
 * against a race where the story became attached (in Shorthand, or locally
 * in WordPress) between the admin page being rendered and this action
 * running.
 */
class CreateDraftFromStoryHandler {

	const STATUS_CREATED          = 'created';
	const STATUS_ALREADY_ATTACHED = 'already_attached';
	const STATUS_ERROR            = 'error';

	/**
	 * Used to fetch the story's current settings, including its `externalId`.
	 *
	 * @readonly
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	/**
	 * Used to create the draft post once unattachment has been re-verified.
	 *
	 * @readonly
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;

	/**
	 * Used to resolve whether the story is already attached to a post.
	 *
	 * @readonly
	 * @var \Shorthand\Services\StoryAttachmentResolver
	 */
	private $attachment_resolver;

	public function __construct( Shorthand $shorthand, PostAPI $post_api, StoryAttachmentResolver $attachment_resolver ) {
		$this->shorthand           = $shorthand;
		$this->post_api            = $post_api;
		$this->attachment_resolver = $attachment_resolver;
	}

	/**
	 * Attempt to create a draft post from the given Shorthand story.
	 *
	 * @param string $story_id Shorthand story ID.
	 * @return array{status: string, post_id: int|null} The outcome status and, when relevant, a post ID.
	 */
	public function handle( string $story_id ): array {
		$settings = $this->shorthand->get_story_settings( $story_id );

		if ( is_wp_error( $settings ) ) {
			return array(
				'status'  => self::STATUS_ERROR,
				'post_id' => null,
			);
		}

		$external_id = $this->extract_external_id( $settings );
		$attachment  = $this->attachment_resolver->resolve( $story_id, $external_id );

		if ( $attachment->is_attached() ) {
			return array(
				'status'  => self::STATUS_ALREADY_ATTACHED,
				'post_id' => $attachment->get_local_post_id(),
			);
		}

		$post = $this->post_api->connect_story( $story_id, null, 'draft' );

		if ( is_wp_error( $post ) ) {
			return array(
				'status'  => self::STATUS_ERROR,
				'post_id' => null,
			);
		}

		return array(
			'status'  => self::STATUS_CREATED,
			'post_id' => isset( $post->ID ) ? (int) $post->ID : null,
		);
	}

	/**
	 * Extract the `externalId` from a story settings response, if any.
	 *
	 * @param mixed[] $settings Story settings, as returned by `Shorthand::get_story_settings()`.
	 */
	private function extract_external_id( array $settings ): ?string {
		$external_id = isset( $settings['external']['externalId'] ) ? $settings['external']['externalId'] : null;

		if ( null === $external_id || '' === $external_id ) {
			return null;
		}

		return (string) $external_id;
	}
}
