<?php
namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use Shorthand\Services\Shorthand;
use Shorthand\Services\Options;
use Shorthand\Services\PostAPI;
use Shorthand\Admin\AdminGateway;
use Shorthand\Core\Loader;

use WP_Post;

/**
 * Registers an action page on adding a new Shorthand post that
 * redirects users to the Shorthand story creation page
 */
class EditWithShorthand {

	/**
	 * @readonly
	 * @var \Shorthand\Services\Shorthand
	 */
	protected $shorthand;
	/**
	 * @readonly
	 * @var \Shorthand\Services\Options
	 */
	protected $options;
	/**
	 * @readonly
	 * @var \Shorthand\Services\PostAPI
	 */
	protected $post_api;
	/**
	 * @readonly
	 * @var string
	 */
	protected $post_type;

	/**
	 * @var \Shorthand\Admin\Actions\StoryReturnHandler
	 */
	private $story_return_handler;

	/**
	 * @var \Shorthand\Admin\Actions\StoryEditorLinkBuilder
	 */
	private $link_builder;

	public function __construct( Shorthand $shorthand, Options $options, PostAPI $post_api, string $post_type, ?StoryReturnHandler $story_return_handler = null, ?StoryEditorLinkBuilder $link_builder = null ) {
		$this->shorthand = $shorthand;
		$this->options   = $options;
		$this->post_api  = $post_api;
		$this->post_type = $post_type;
		$this->link_builder = $link_builder ? $link_builder : new StoryEditorLinkBuilder();
		$this->story_return_handler = $story_return_handler ? $story_return_handler : new StoryReturnHandler( $post_api, $shorthand, new AdminGateway(), $this->link_builder, $post_type );
	}

	public function define_redirect_and_return_pages( Loader $loader ): void {
		/* when Shorthand needs to associate a story ID with a WP post, it redirects back to here */
		$loader->add_action(
			'admin_post_nopriv_shorthand_return',
			$this,
			'redirect_to_login'
		);

		$loader->add_action(
			'admin_post_shorthand_return',
			$this,
			'render_return_page'
		);

		$loader->add_action(
			'load-post-new.php',
			$this,
			'render_story_creation_page',
			10,
			1
		);
		$loader->add_action(
			'admin_post_shorthand_editor',
			$this,
			'render_story_editor_page'
		);
	}

	public function get_url( ?\WP_Post $post = null, ?string $story_id = null ): string {
		return $this->link_builder->build( $post ? (int) $post->ID : null, $story_id );
	}

	public function redirect_to_login(): void {
		// add_query_arg with no arguments returns the current URL with proper scheme.
		$current_url = add_query_arg( array() );

		// Redirect to login page.
		wp_safe_redirect( wp_login_url( $current_url ) );
		exit;
	}

	public function render_story_creation_page(): void {
		global $typenow;

		if ( ! isset( $typenow ) || $typenow !== $this->post_type ) {
			return;
		}

		$this->check_permissions();

		$redirect_url = $this->get_redirect_url();

		/* The user is redirected to an authorised page within the Shorthand app */
		wp_safe_redirect( $redirect_url );
		exit;
	}

	public function render_story_editor_page(): void {
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			wp_die( esc_html__( 'Missing nonce.', 'the-shorthand-editor' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'shorthand_redirect' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'the-shorthand-editor' ) );
		}

		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : null;
		$story_id = isset( $_GET['story'] ) ? sanitize_text_field( wp_unslash( $_GET['story'] ) ) : null;

		$this->check_permissions( $post_id );

		$redirect_url = $this->get_redirect_url( $post_id, $story_id );

		/* The user is redirected to an authorised page within the Shorthand app */
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Returns a redirect URL for assigning a story ID to a WP post
	 */
	public function get_callback_url( ?string $post_id = null ): string {
		$params = array();
		if ( $post_id ) {
			$params['post'] = $post_id;
		} else {
			$params['create'] = $this->post_type;
		}

		$params['_wpnonce'] = wp_create_nonce( 'shorthand_return' );

		return add_query_arg(
			$params,
			admin_url( 'admin-post.php?action=shorthand_return' )
		);
	}

	public function render_return_page() {
		/* Unless the request is for story creation, this redirect is essentially just a deep-link to the WP editor */
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			wp_die( esc_html__( 'Missing nonce.', 'the-shorthand-editor' ) );
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'shorthand_return' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'the-shorthand-editor' ) );
		}

		$post_id  = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : null;
		$story_id = isset( $_REQUEST['story'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['story'] ) ) : null;

		$error = isset( $_REQUEST['error'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['error'] ) ) : null;

		$target      = isset( $_REQUEST['target'] ) ? sanitize_url( wp_unslash( $_REQUEST['target'] ) ) : null;
		$create_type = isset( $_REQUEST['create'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['create'] ) ) : null;

		if ( ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) || ( ! $post_id && ! current_user_can( 'edit_posts' ) ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'the-shorthand-editor' ),
				'Permission Denied',
				array(
					'back_link' => true,
				)
			);
		}

		$result = $this->story_return_handler->handle( $post_id, $story_id, $error, $target, $create_type );

		$this->respond( $result );
	}

	private function get_all_stories_url(): string {
		return admin_url(
			"edit.php?post_type={$this->post_type}"
		);
	}

	private function respond( ActionResult $result ): void {
		if ( $result->isRedirect() ) {
			wp_safe_redirect( $result->getRedirectUrl() );
			exit;
		}

		$args = array();
		if ( $result->getLinkUrl() ) {
			$args['link_url'] = esc_url( $result->getLinkUrl() );
		}
		if ( $result->getLinkText() ) {
			$args['link_text'] = esc_html( $result->getLinkText() );
		}

		wp_die(
			esc_html( $result->getMessage() ),
			esc_html( $result->getTitle() ),
			$args
		);
	}

	private function check_permissions( ?int $post_id = null ): void {
		if ( $post_id ? ! current_user_can( 'edit_post', $post_id ) : ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'the-shorthand-editor' ),
				esc_html__( 'Permission Denied', 'the-shorthand-editor' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}


	private function get_redirect_url( ?int $post_id = null, ?string $story_id = null ): string {
		$target_url = $this->get_callback_url( $post_id );
		if ( $story_id ) {
			return $this->shorthand->get_story_editor_url(
				$target_url,
				$story_id
			);
		}

		return $this->shorthand->get_story_creation_url( $target_url );
	}
}
