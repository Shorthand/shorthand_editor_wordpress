<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells a save the editor made from a save by anything else.
 *
 * Only the edit screen, Quick Edit and bulk edit may fetch a story, check
 * Shorthand for a new version or push the title. Each prints a nonce in the
 * field `shorthand_story_intent`. A save carries intent when the request was
 * sent by one of them, names the post being saved, and its nonce is valid.
 *
 * Each post's intent is claimed once. A nested save of the same post in the
 * same request, as by another plugin's `save_post` hook, finds it used.
 * One instance serves the whole request.
 */
class StorySaveIntent {

	/**
	 * Request field that carries the nonce.
	 */
	const FIELD = 'shorthand_story_intent';

	/**
	 * Nonce action of Quick Edit and bulk edit, which share one template for every post.
	 */
	const LIST_ACTION = 'shorthand_story_list_save';

	/**
	 * Posts whose intent this request has used.
	 *
	 * @var array<int, true>
	 */
	private $claimed = array();

	/**
	 * Posts the edit screen saved with a missing or failed nonce.
	 *
	 * @var array<int, true>
	 */
	private $expired = array();

	/**
	 * Nonce action of the edit screen for one post.
	 *
	 * @param int $post_id Post being edited.
	 * @return string
	 */
	public static function edit_action( int $post_id ): string {
		return 'shorthand_story_save_' . $post_id;
	}

	/**
	 * Uses the request's intent for one post, if it carries a valid one.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool True once per request for a post the editor saved.
	 */
	public function claim( int $post_id ): bool {
		if ( isset( $this->claimed[ $post_id ] ) ) {
			return false;
		}

		$request = $this->match( $post_id );
		if ( null === $request ) {
			return false;
		}

		if ( '' === $request['nonce'] || false === wp_verify_nonce( $request['nonce'], $request['action'] ) ) {
			/* An edit screen loaded before this field existed sends none; it is as stale as an expired one. */
			if ( 'editpost' === $request['source'] ) {
				$this->expired[ $post_id ] = true;
			}
			return false;
		}

		$this->claimed[ $post_id ] = true;
		return true;
	}

	/**
	 * Whether this request is the edit screen's autosave of this post.
	 *
	 * The heartbeat autosave of the author's own draft saves the post through
	 * `edit_post()`. Core has already checked its `update-post_{id}` nonce in
	 * `wp_autosave()`; it is checked again here because this runs on a filter.
	 * An autosave carries the author's title, so it counts as intent for the
	 * title only: it never starts a pull.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool
	 */
	public function is_autosave( int $post_id ): bool {
		// phpcs:disable WordPress.Security.NonceVerification -- The nonce is verified below.
		if ( 0 === $post_id || ! isset( $_POST['action'] ) || 'heartbeat' !== sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			return false;
		}

		$target = isset( $_POST['data']['wp_autosave']['post_id'] ) ? absint( $_POST['data']['wp_autosave']['post_id'] ) : 0;
		$nonce  = isset( $_POST['data']['wp_autosave']['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['data']['wp_autosave']['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		return $target === $post_id && '' !== $nonce && false !== wp_verify_nonce( $nonce, 'update-post_' . $post_id );
	}

	/**
	 * Whether the edit screen saved this post with a missing or failed nonce.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool
	 */
	public function expired( int $post_id ): bool {
		return isset( $this->expired[ $post_id ] );
	}

	/**
	 * Reads the request that saves this post.
	 *
	 * Bulk edit submits by GET (`wp-admin/edit.php`), so its fields are read
	 * from `$_REQUEST`.
	 *
	 * @param int $post_id Post being saved.
	 * @return array{source: string, action: string, nonce: string}|null Null when no editor request names this post.
	 */
	private function match( int $post_id ): ?array {
		if ( 0 === $post_id ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- These fields locate the nonce that claim() verifies.
		$source = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( 'editpost' === $source || 'inline-save' === $source ) {
			$target = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
			$nonce  = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
			$action = 'editpost' === $source ? self::edit_action( $post_id ) : self::LIST_ACTION;
		} elseif ( isset( $_REQUEST['bulk_edit'] ) ) {
			$source  = 'bulk_edit';
			$targets = isset( $_REQUEST['post'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['post'] ) ) : array();
			$target  = in_array( $post_id, $targets, true ) ? $post_id : 0;
			$nonce   = isset( $_REQUEST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::FIELD ] ) ) : '';
			$action  = self::LIST_ACTION;
		} else {
			return null;
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( $target !== $post_id ) {
			return null;
		}

		return array(
			'source' => $source,
			'action' => $action,
			'nonce'  => $nonce,
		);
	}
}
