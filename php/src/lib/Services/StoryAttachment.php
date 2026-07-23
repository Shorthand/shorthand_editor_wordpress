<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The resolved attachment state of a Shorthand story with respect to
 * this WordPress instance.
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
	 * Whether the story's `externalId` points at a post that does not exist
	 * in this WordPress instance — either a post on another instance, or a
	 * stale link to a post that was later deleted here.
	 *
	 * @readonly
	 * @var bool
	 */
	private $attached_elsewhere;

	public function __construct( ?int $local_post_id, bool $attached_elsewhere ) {
		$this->local_post_id      = $local_post_id;
		$this->attached_elsewhere = $attached_elsewhere;
	}

	/**
	 * Whether the story is associated with a post in this WordPress instance.
	 *
	 * Only a local post blocks attaching the story to a fresh draft; an
	 * `externalId` pointing outside this instance does not (see
	 * `is_attached_elsewhere()`).
	 */
	public function is_attached(): bool {
		return null !== $this->local_post_id;
	}

	/**
	 * The ID of the local post carrying this story's `story_id` meta, if any.
	 */
	public function get_local_post_id(): ?int {
		return $this->local_post_id;
	}

	/**
	 * Whether the story's `externalId` points at a post that does not exist
	 * in this WordPress instance.
	 */
	public function is_attached_elsewhere(): bool {
		return $this->attached_elsewhere;
	}
}
