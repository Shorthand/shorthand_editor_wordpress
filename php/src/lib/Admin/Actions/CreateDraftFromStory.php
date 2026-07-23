<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Admin\StoriesPage;
use Shorthand\Core\Loader;

/**
 * Registers the `admin_post` handler that creates a draft WordPress post for
 * an unattached Shorthand story, invoked from the Shorthand Stories admin
 * page.
 */
class CreateDraftFromStory {

	const ACTION = 'shorthand_create_draft';

	/**
	 * Handles the unattachment guard and draft post creation.
	 *
	 * @readonly
	 * @var \Shorthand\Admin\Actions\CreateDraftFromStoryHandler
	 */
	private $handler;

	/**
	 * URL of the Shorthand Stories admin page to redirect back to.
	 *
	 * @readonly
	 * @var string
	 */
	private $stories_page_url;

	public function __construct( CreateDraftFromStoryHandler $handler, string $stories_page_url ) {
		$this->handler          = $handler;
		$this->stories_page_url = $stories_page_url;
	}

	/**
	 * Register the `admin_post` hook for this action.
	 *
	 * @param Loader $loader Hook loader to register with.
	 */
	public function define_hooks( Loader $loader ): void {
		$loader->add_action( 'admin_post_' . self::ACTION, $this, 'handle_request' );
	}

	/**
	 * Build a nonce-protected URL that triggers draft creation for the given
	 * story from the Shorthand Stories admin page.
	 *
	 * @param string $story_id Shorthand story ID.
	 * @return string The nonce-protected `admin-post.php` URL.
	 */
	public static function build_action_url( string $story_id ): string {
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'story'  => rawurlencode( $story_id ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::get_nonce_action( $story_id ) );
	}

	/**
	 * The nonce action name used to protect requests for a given story.
	 *
	 * @param string $story_id Shorthand story ID.
	 */
	private static function get_nonce_action( string $story_id ): string {
		return self::ACTION . '_' . $story_id;
	}

	/**
	 * Handle the `admin_post_shorthand_create_draft` request.
	 *
	 * Verifies capability and nonce, delegates the unattachment guard and
	 * post creation to `CreateDraftFromStoryHandler`, then redirects back to
	 * the Shorthand Stories page with an outcome notice.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( StoriesPage::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'the-shorthand-editor' ),
				'',
				array( 'response' => 403 )
			);
		}

		$story_id = isset( $_GET['story'] ) ? sanitize_text_field( wp_unslash( $_GET['story'] ) ) : '';

		if (
			'' === $story_id
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::get_nonce_action( $story_id ) )
		) {
			wp_die(
				esc_html__( 'Invalid or expired request. Please try again from the Shorthand Stories page.', 'the-shorthand-editor' ),
				esc_html__( 'Invalid Request', 'the-shorthand-editor' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$result = $this->handler->handle( $story_id );

		$this->redirect( $result, $story_id );
	}

	/**
	 * Redirect back to the Shorthand Stories page with an outcome notice.
	 *
	 * @param array{status: string, post_id: int|null} $result   Outcome of `CreateDraftFromStoryHandler::handle()`.
	 * @param string                                   $story_id Shorthand story ID the request was for.
	 */
	private function redirect( array $result, string $story_id ): void {
		$args = array(
			'shorthand_notice' => $result['status'],
			'story'            => $story_id,
		);

		if ( ! empty( $result['post_id'] ) ) {
			$args['shorthand_post'] = $result['post_id'];
		}

		wp_safe_redirect( add_query_arg( $args, $this->stories_page_url ) );
		exit;
	}
}
