<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Shorthand\Admin\AdminGateway;
use Shorthand\Core\Loader;
use Shorthand\Services\Shorthand;

class ReturnToConnect {

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	/**
	 * @var \Shorthand\Admin\Actions\ConnectionCompletionService
	 */
	private $connection_completion_service;

	/**
	 * @var \Shorthand\Admin\AdminGateway
	 */
	private $admin_gateway;

	public function __construct( Shorthand $shorthand, ConnectionCompletionService $connection_completion_service, AdminGateway $admin_gateway ) {
		$this->shorthand                     = $shorthand;
		$this->connection_completion_service = $connection_completion_service;
		$this->admin_gateway                 = $admin_gateway;
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
				esc_html__( 'You do not have permission to manage Shorthand settings. Please contact your site administrator to request access.', 'the-shorthand-editor' ),
				esc_html__( 'Permission Denied', 'the-shorthand-editor' ),
				array(
					'response'  => 403,
					'link_url'  => esc_url( admin_url( '/' ) ),
					'link_text' => esc_html__( 'Return to Dashboard', 'the-shorthand-editor' ),
				)
			);
			exit;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shorthand_connect_complete' ) ) {
			wp_die(
				esc_html__( 'Your connection request has expired. This can happen if the connection process took too long. Please try again.', 'the-shorthand-editor' ),
				esc_html__( 'Connection Expired', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( admin_url( 'admin-post.php?action=shorthand_connect_start' ) ),
					'link_text' => esc_html__( 'Try connecting again', 'the-shorthand-editor' ),
				)
			);
			exit;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $token ) {
			wp_die(
				esc_html__( 'The connection to Shorthand was not completed. You can try again from the settings page when you are ready.', 'the-shorthand-editor' ),
				esc_html__( 'Connection Canceled', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( $this->admin_gateway->get_settings_page_url() ),
					'link_text' => esc_html__( 'Go to Shorthand Settings', 'the-shorthand-editor' ),
				)
			);
			exit;
		}

		$raw_post_id  = isset( $_GET['post_id'] ) ? wp_unslash( $_GET['post_id'] ) : '';
		$post_id      = is_numeric( $raw_post_id ) ? absint( $raw_post_id ) : 0;
		$redirect_url = $this->connection_completion_service->complete( $token, $post_id );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
