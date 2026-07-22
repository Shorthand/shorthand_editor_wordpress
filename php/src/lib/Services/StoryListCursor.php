<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derives opaque pagination cursors for the Shorthand stories list endpoint.
 *
 * Mirrors the server's cursor shape (an `updatedAt`/`id` pair, base64-JSON
 * encoded), so a cursor produced here can be sent straight back as the
 * `cursor` param of `Shorthand::list_stories()`.
 */
class StoryListCursor {

	/**
	 * Encode a cursor pointing just after the given story.
	 *
	 * @param string $updated_at The story's `updatedAt` value.
	 * @param string $id         The story's ID.
	 */
	public static function encode( string $updated_at, string $id ): string {
		return base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			(string) wp_json_encode(
				array(
					'updatedAt' => $updated_at,
					'id'        => $id,
				)
			)
		);
	}

	/**
	 * Derive the next page's cursor from a page of story results.
	 *
	 * Returns null when the page was shorter than the requested limit
	 * (indicating there is no further page), or when the last item is
	 * missing the fields required to build a cursor.
	 *
	 * @param mixed[] $stories A page of story objects, as returned by `Shorthand::list_stories()`.
	 * @param int     $limit   The page size that was requested.
	 */
	public static function next_cursor( array $stories, int $limit ): ?string {
		if ( count( $stories ) < $limit ) {
			return null;
		}

		$last = end( $stories );

		if ( ! is_array( $last ) || empty( $last['updatedAt'] ) || ! isset( $last['id'] ) ) {
			return null;
		}

		return self::encode( (string) $last['updatedAt'], (string) $last['id'] );
	}
}
