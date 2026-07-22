<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves whether a Shorthand story is already attached to a WordPress post.
 *
 * Attachment is resolved two ways: the API-reported `externalId` on the
 * story, and a local lookup for a `tse_story` post (in any status) carrying
 * the story's ID in its `story_id` meta. Either signal is sufficient to
 * treat the story as attached. A local post takes precedence when both are
 * available, since the `externalId` may be stale — for example if the local
 * post was later deleted.
 */
class StoryAttachmentResolver {

	/**
	 * Used to find a local post carrying a given story's ID in its meta.
	 *
	 * @readonly
	 * @var \Shorthand\Services\StoryLocalLookup
	 */
	private $local_lookup;

	public function __construct( StoryLocalLookup $local_lookup ) {
		$this->local_lookup = $local_lookup;
	}

	/**
	 * Resolve the attachment state of a Shorthand story.
	 *
	 * @param string      $story_id    Shorthand story ID.
	 * @param string|null $external_id The story's `externalId`, as reported by the Shorthand API.
	 */
	public function resolve( string $story_id, ?string $external_id ): StoryAttachment {
		$local_post_id = $this->local_lookup->find_post_id_by_story_id( $story_id );

		if ( null !== $local_post_id ) {
			return new StoryAttachment( $local_post_id, false );
		}

		$stale_external_id = null !== $external_id && '' !== $external_id;

		return new StoryAttachment( null, $stale_external_id );
	}
}
