<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation for Shorthand story identifiers.
 *
 * A story ID is interpolated into file system paths, so it is validated
 * against an allowlist rather than transformed. `sanitize_text_field()` leaves
 * `/`, `\` and `..` in place, and `sanitize_key()` lowercases, which would
 * merge two story IDs differing only in case.
 */
class StoryId {

	/**
	 * Shorthand story IDs are opaque alphanumeric tokens.
	 */
	const PATTERN = '/^[A-Za-z0-9]+$/';

	/**
	 * Reports whether a value is usable as a story ID and as a path segment.
	 *
	 * @param mixed $story_id Value to test.
	 */
	public static function is_valid( $story_id ): bool {
		return is_string( $story_id ) && 1 === preg_match( self::PATTERN, $story_id );
	}

	/**
	 * Reduces an untrusted value to a story ID, or to an empty string.
	 *
	 * Registered as the `sanitize_callback` for the `story_id` post meta, where
	 * rejecting a value means storing nothing rather than raising an error.
	 *
	 * @param mixed $story_id Value to sanitize.
	 */
	public static function sanitize( $story_id ): string {
		return self::is_valid( $story_id ) ? $story_id : '';
	}
}
