<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Imports a story's cover image as the post's featured image.
 *
 * One rule governs every write: the plugin sets the featured image only when
 * it set the current one, or when there is none. The attachment the plugin
 * set is recorded in `story_cover_attachment` and compared with the post's
 * thumbnail on every publish, so a featured image chosen in WordPress is
 * never overwritten and no change event needs to be observed.
 *
 * `story_cover` records the cover a publish last evaluated. It is not a
 * cache of the API: a cover seen only by the editor refresh is never written
 * there, or the next publish would take a changed cover for the one it
 * already imported.
 */
class StoryCover {

	const STATE_NONE       = 'none';
	const STATE_MANUAL     = 'manual';
	const STATE_OVERRIDDEN = 'overridden';
	const STATE_CURRENT    = 'current';
	const STATE_PENDING    = 'pending';
	const STATE_UNKNOWN    = 'unknown';

	const OUTCOME_IMPORTED = 'imported';
	const OUTCOME_FAILED   = 'failed';

	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Source of the story settings.
	 *
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	public function __construct( Shorthand $shorthand ) {
		$this->shorthand = $shorthand;
	}

	/**
	 * Reduces a cover record from the API, or from meta, to its known keys.
	 *
	 * Registered as the `story_cover` meta sanitizer, so the stored value has
	 * the same shape wherever it came from. The API reports the file under
	 * `signedUrl`; it is kept as `url`, an opaque, short-lived address that
	 * nothing renders from meta.
	 *
	 * @param mixed $cover Cover record.
	 * @return array{id: string, url: string, mime: string, name: string, size: int, width: int, height: int}|null Null when the record is not a usable cover.
	 */
	public static function sanitize( $cover ): ?array {
		if ( ! is_array( $cover ) || empty( $cover['id'] ) ) {
			return null;
		}

		$url = (string) ( $cover['signedUrl'] ?? $cover['url'] ?? '' );
		if ( '' === $url ) {
			return null;
		}

		return array(
			'id'     => sanitize_text_field( (string) $cover['id'] ),
			'url'    => esc_url_raw( $url ),
			'mime'   => sanitize_mime_type( (string) ( $cover['mime'] ?? '' ) ),
			'name'   => sanitize_text_field( (string) ( $cover['name'] ?? '' ) ),
			'size'   => absint( $cover['size'] ?? 0 ),
			'width'  => absint( $cover['width'] ?? 0 ),
			'height' => absint( $cover['height'] ?? 0 ),
		);
	}

	/**
	 * The story's cover as Shorthand reports it.
	 *
	 * Writes nothing to the post. With `$cached`, a result from the last few
	 * minutes is reused, so repeated editor loads do not each reach the API.
	 *
	 * @param string $story_id Shorthand story ID.
	 * @param bool   $cached   Reuse a recent result when there is one.
	 * @return array|null|\WP_Error The cover, null when the story has none, or the failed request.
	 */
	public function fetch( string $story_id, bool $cached = false ) {
		if ( $cached ) {
			$hit = get_transient( $this->cache_key( $story_id ) );
			if ( is_array( $hit ) && array_key_exists( 'cover', $hit ) ) {
				return $hit['cover'];
			}
		}

		$settings = $this->shorthand->get_story_settings( $story_id );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		return $this->read( $story_id, $settings )['cover'];
	}

	/**
	 * What publishing would do with a cover, without doing it.
	 *
	 * The classic editor holds an unsaved featured image choice in the form,
	 * so callers there pass the id the form shows rather than the saved one.
	 *
	 * @param int        $post_id   Story post.
	 * @param array|null $cover     Incoming cover, or null when the story has none.
	 * @param int|null   $thumbnail Featured image to judge against; the saved one when null.
	 * @return string One of the `STATE_*` values other than `STATE_UNKNOWN`.
	 */
	public function state( int $post_id, ?array $cover, ?int $thumbnail = null ): string {
		if ( null === $cover ) {
			return self::STATE_NONE;
		}

		$attachment = (int) get_post_meta( $post_id, 'story_cover_attachment', true );
		if ( null === $thumbnail ) {
			$thumbnail = (int) get_post_thumbnail_id( $post_id );
		}

		if ( ! $attachment && $thumbnail ) {
			return self::STATE_MANUAL;
		}

		if ( $attachment && $attachment !== $thumbnail ) {
			return self::STATE_OVERRIDDEN;
		}

		$stored = self::sanitize( get_post_meta( $post_id, 'story_cover', true ) );
		if ( $attachment && null !== $stored && $stored['id'] === $cover['id'] ) {
			return self::STATE_CURRENT;
		}

		return self::STATE_PENDING;
	}

	private function fix_api_url( string $url ): string {
		if ( strncmp( $url, 'https://localhost', strlen( 'https://localhost' ) ) === 0 ) {
			return str_replace( 'https://localhost', 'https://host.docker.internal', $url );
		}
		return $url;
	}

	/**
	 * Applies the story's cover as the featured image, where the rule allows.
	 *
	 * Never fails a publish. The story content is already stored by the time
	 * this runs, so a cover that cannot be fetched or imported is left for
	 * the next publish to try again.
	 *
	 * With `$replace`, an image chosen in WordPress is replaced too. Only the
	 * editor's "Use story cover" button passes it: the author asked.
	 *
	 * @param int    $post_id  Post being published.
	 * @param string $story_id Shorthand story ID.
	 * @param bool   $replace  Import even when the author set the featured image.
	 * @return string The state the post was found in, or an `OUTCOME_*` value when an import ran.
	 */
	public function sync( int $post_id, string $story_id, bool $replace = false ): string {
		$settings = $this->shorthand->get_story_settings( $story_id );
		if ( is_wp_error( $settings ) ) {
			return self::OUTCOME_FAILED;
		}

		$story     = $this->read( $story_id, $settings );
		$cover     = $story['cover'];
		$thumbnail = (int) get_post_thumbnail_id( $post_id );
		$state     = $this->state( $post_id, $cover, $thumbnail );

		$cover['url'] = $this->fix_api_url( $cover['url'] );

		if ( self::STATE_NONE === $state ) {
			return $state;
		}

		$import = self::STATE_PENDING === $state || ( $replace && self::STATE_CURRENT !== $state );
		if ( ! $import ) {
			update_post_meta( $post_id, 'story_cover', $cover );
			return $state;
		}

		$attachment_id = $this->import( $post_id, $cover, $story['description'] );
		if ( is_wp_error( $attachment_id ) ) {
			return self::OUTCOME_FAILED;
		}

		$previous = (int) get_post_meta( $post_id, 'story_cover_attachment', true );

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, 'story_cover', $cover );
		update_post_meta( $post_id, 'story_cover_attachment', $attachment_id );

		/*
		 * The earlier attachment is the plugin's to remove only while it is
		 * still the featured image; once the author moved away from it, it
		 * may be in use elsewhere. Removed after the thumbnail moves, because
		 * deleting an attachment clears every `_thumbnail_id` pointing at it.
		 */
		if ( $previous && $previous !== $attachment_id && $previous === $thumbnail ) {
			wp_delete_attachment( $previous, true );
		}

		return self::OUTCOME_IMPORTED;
	}

	/**
	 * Tells the author what publishing will do with the cover.
	 *
	 * @param string $state A `STATE_*` value.
	 */
	public function describe( string $state ): string {
		switch ( $state ) {
			case self::STATE_NONE:
				return __( 'This story has no cover image.', 'the-shorthand-editor' );
			case self::STATE_MANUAL:
				return __( 'The featured image was chosen in WordPress. Publishing will keep it.', 'the-shorthand-editor' );
			case self::STATE_OVERRIDDEN:
				return __( 'The featured image was changed in WordPress. Publishing will keep it.', 'the-shorthand-editor' );
			case self::STATE_CURRENT:
				return __( 'The story cover is the featured image.', 'the-shorthand-editor' );
			case self::STATE_PENDING:
				return __( 'The story cover will become the featured image when the story is next published.', 'the-shorthand-editor' );
			default:
				return __( 'Checking the story cover…', 'the-shorthand-editor' );
		}
	}
	/**
	 * Copies the cover into the media library.
	 *
	 * Runs inside WP-Cron, where the admin includes are not loaded.
	 *
	 * @param int    $post_id Post the attachment belongs to.
	 * @param array  $cover   Sanitized cover.
	 * @param string $alt     Alt text for the attachment.
	 * @return int|\WP_Error Attachment ID.
	 */
	private function import( int $post_id, array $cover, string $alt ) {
		if ( 0 !== strpos( $cover['mime'], 'image/' ) ) {
			return new WP_Error( 'cover', 'The story cover is not an image.', $cover['mime'] );
		}

		/**
		 * Filters the largest story cover the plugin will import, in bytes.
		 *
		 * @param int $max_bytes Ceiling on the cover file size.
		 */
		$max_bytes = (int) apply_filters( 'theshed_cover_max_bytes', 20 * MB_IN_BYTES );
		if ( $cover['size'] > $max_bytes ) {
			return new WP_Error( 'cover', 'The story cover is too large to import.', $cover['size'] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $cover['url'] );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $this->file_name( $cover ),
				'tmp_name' => $tmp,
			),
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			/* A failed sideload leaves the download behind. */
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return $attachment_id;
	}

	/**
	 * Splits a settings response into the cover and the alt text, and caches the cover.
	 *
	 * @param string $story_id Shorthand story ID.
	 * @param array  $settings Decoded `GET /v2/stories/:id/settings` body.
	 * @return array{cover: array|null, description: string}
	 */
	private function read( string $story_id, array $settings ): array {
		$meta  = isset( $settings['meta'] ) && is_array( $settings['meta'] ) ? $settings['meta'] : array();
		$cover = self::sanitize( $meta['cover'] ?? null );

		set_transient( $this->cache_key( $story_id ), array( 'cover' => $cover ), self::CACHE_TTL );

		return array(
			'cover'       => $cover,
			'description' => isset( $meta['description'] ) ? sanitize_textarea_field( (string) $meta['description'] ) : '',
		);
	}

	/**
	 * The file name the attachment is stored under; must carry an extension.
	 *
	 * @param array $cover Sanitized cover.
	 */
	private function file_name( array $cover ): string {
		if ( '' !== $cover['name'] ) {
			return $cover['name'];
		}

		return wp_basename( (string) wp_parse_url( $cover['url'], PHP_URL_PATH ) );
	}

	private function cache_key( string $story_id ): string {
		return 'shorthand_story_cover_' . $story_id;
	}
}
