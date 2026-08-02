<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Shorthand\Admin\AdminGateway;
use Shorthand\Admin\ConnectionErrorPage;
use Shorthand\Core\Loader;
use Shorthand\Services\ConnectionFailure;
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

	/**
	 * @var \Shorthand\Admin\ConnectionErrorPage
	 */
	private $connection_error_page;

	public function __construct( Shorthand $shorthand, ConnectionCompletionService $connection_completion_service, AdminGateway $admin_gateway, ?ConnectionErrorPage $connection_error_page = null ) {
		$this->shorthand                     = $shorthand;
		$this->connection_completion_service = $connection_completion_service;
		$this->admin_gateway                 = $admin_gateway;
		$this->connection_error_page         = $connection_error_page ? $connection_error_page : new ConnectionErrorPage();
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
			$this->connection_error_page->render( ConnectionFailure::permission_to_complete() );
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shorthand_connect_complete' ) ) {
			$this->connection_error_page->render( ConnectionFailure::expired() );
			return;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $token ) {
			$this->connection_error_page->render( ConnectionFailure::canceled() );
			return;
		}

		$raw_post_id = isset( $_GET['post_id'] ) ? wp_unslash( $_GET['post_id'] ) : '';
		$post_id     = is_numeric( $raw_post_id ) ? absint( $raw_post_id ) : 0;

		/*
		 * Completion talks to Shorthand and renders its own failure pages.
		 * Anything that still escapes must land on a branded page rather
		 * than WordPress's raw critical-error screen.
		 */
		try {
			$redirect_url = $this->connection_completion_service->complete( $token, $post_id );
		} catch ( \Throwable $unexpected ) {
			$this->connection_error_page->render(
				ConnectionFailure::unexpected_error()->with_diagnostics(
					array(
						'exception' => get_class( $unexpected ),
						'message'   => $unexpected->getMessage(),
					)
				)
			);
			return;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
