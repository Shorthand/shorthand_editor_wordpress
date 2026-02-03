<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Shorthand\Core\Loader;
use Shorthand\Services\Shorthand;

class ReturnToConnect {

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	public function __construct( Shorthand $shorthand ) {
		$this->shorthand = $shorthand;
	}

	/**
	 * Returns a redirect URL for the connect action
	 */
	public function define_return_page( Loader $loader ): void {
		$loader->add_action(
			'admin_post_nopriv_shorthand_connect_complete',
			$this,
			'redirect_to_login',
			10,
			0
		);

		$loader->add_action(
			'admin_post_shorthand_connect_complete',
			$this,
			'render_page',
			10,
			0
		);
	}

	public function get_callback_url(): string {
		$params             = array();
		$params['_wpnonce'] = wp_create_nonce( 'shorthand_connect_complete' );

		return add_query_arg(
			$params,
			admin_url( 'admin-post.php?action=shorthand_connect_complete' )
		);
	}

	public function redirect_to_login(): void {
		// add_query_arg with no arguments returns the current URL with proper scheme.
		$current_url = add_query_arg( array() );

		// Redirect to login page.
		wp_safe_redirect( wp_login_url( $current_url ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'the-shorthand-editor' ),
				esc_html__( 'Permission Denied', 'the-shorthand-editor' ),
				array(
					'response'  => 403,
					'link_url'  => esc_url( admin_url( 'plugins.php' ) ),
					'link_text' => esc_html__( 'Return to Plugins', 'the-shorthand-editor' ),
				)
			);
			exit;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shorthand_connect_complete' ) ) {
			wp_die(
				esc_html__( 'Invalid connection request: expired nonce.', 'the-shorthand-editor' ),
				esc_html__( 'Error', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( admin_url( 'plugins.php' ) ),
					'link_text' => esc_html__( 'Return to Plugins', 'the-shorthand-editor' ),
				)
			);
			exit;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $token ) {
			wp_die(
				esc_html__( 'The connection was canceled by the user.', 'the-shorthand-editor' ),
				esc_html__( 'Canceled', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( admin_url( 'plugins.php' ) ),
					'link_text' => esc_html__( 'Return to Plugins', 'the-shorthand-editor' ),
				)
			);
			exit;
		}

		// Complete the connection.
		$err = $this->shorthand->connect( $token );

		// Redirect to the Shorthand dashboard or a specific post if needed.
		$post_id = isset( $_GET['post_id'] ) && is_numeric( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		$redirect_url = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'options-general.php?page=shorthand-settings' );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
