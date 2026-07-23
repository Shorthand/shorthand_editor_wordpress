<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves whether a Shorthand story is attached to a post in this
 * WordPress instance.
 *
 * A story is attached here when a local `tse_story` post (in any status)
 * carries the story's ID in its `story_id` meta. The API-reported
 * `externalId` is only informational: when it is set but no local post
 * matches, the story is attached elsewhere (another WordPress instance, or
 * a stale link to a since-deleted local post) — which does not prevent
 * attaching it to a fresh draft here.
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

		$attached_elsewhere = null !== $external_id && '' !== $external_id;

		return new StoryAttachment( null, $attached_elsewhere );
	}
}
