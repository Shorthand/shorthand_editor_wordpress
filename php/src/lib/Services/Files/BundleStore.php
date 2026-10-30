<?php

namespace Shorthand\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\StoryId;

/**
 * Opens the file bundle of one story.
 *
 * The story ID is interpolated into a path, so it is validated here rather
 * than in each caller: a bundle that cannot be opened is a bundle that cannot
 * be written to, and no caller has to remember the check.
 */
class BundleStore {

	/**
	 * Uploads directory every bundle this store opens is written to.
	 *
	 * @var \Shorthand\Services\Files\Uploads
	 */
	private $uploads;

	/**
	 * Nothing is read or written here; the store only hands out bundles.
	 *
	 * @param \Shorthand\Services\Files\Uploads $uploads Uploads directory of this site.
	 */
	public function __construct( Uploads $uploads ) {
		$this->uploads = $uploads;
	}

	/**
	 * The bundle of one story, or null where the story ID is unusable.
	 *
	 * @param int|string $post_id  Post the bundle belongs to.
	 * @param mixed      $story_id Shorthand story ID.
	 */
	public function open( $post_id, $story_id ): ?Bundle {
		if ( ! StoryId::is_valid( $story_id ) ) {
			return null;
		}

		return new Bundle( $this->uploads, absint( $post_id ), $story_id );
	}
}
