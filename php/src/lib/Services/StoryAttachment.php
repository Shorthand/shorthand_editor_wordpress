<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The resolved attachment state of a Shorthand story with respect to
 * WordPress.
 */
class StoryAttachment {

	/**
	 * The ID of the local post linked to the story, if one exists.
	 *
	 * @readonly
	 * @var int|null
	 */
	private $local_post_id;

	/**
	 * Whether the story's `externalId` points at a WordPress post that no
	 * longer exists locally.
	 *
	 * @readonly
	 * @var bool
	 */
	private $stale_external_id;

	public function __construct( ?int $local_post_id, bool $stale_external_id ) {
		$this->local_post_id     = $local_post_id;
		$this->stale_external_id = $stale_external_id;
	}

	/**
	 * Whether the story is already associated with a WordPress post, either
	 * via a live local post or a stale `externalId` referencing a post that
	 * no longer exists locally.
	 */
	public function is_attached(): bool {
		return null !== $this->local_post_id || $this->stale_external_id;
	}

	/**
	 * The ID of the local post carrying this story's `story_id` meta, if any.
	 */
	public function get_local_post_id(): ?int {
		return $this->local_post_id;
	}

	/**
	 * Whether the story's `externalId` points at a WordPress post that no
	 * longer exists locally.
	 */
	public function has_stale_external_id(): bool {
		return $this->stale_external_id;
	}
}
