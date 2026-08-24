<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Admin\ConnectionErrorPage;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\ConnectionFailure;
use Shorthand\Services\Shorthand;
use Shorthand\Core\Loader;


/**
 * Registers an action page that redirects users to the Shorthand integration page
 */
class RedirectToIntegration {

	/**
	 * @readonly
	 * @var \Shorthand\Services\Shorthand
	 */
	protected $shorthand;
	/**
	 * @readonly
	 * @var \Shorthand\Admin\Actions\ReturnToConnect
	 */
	protected $return_to_connect;
	/**
	 * @readonly
	 * @var string
	 */
	protected $failure_url;

	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	/**
	 * @var \Shorthand\Admin\ConnectionErrorPage
	 */
	private $connection_error_page;

	public function __construct( Shorthand $shorthand, ReturnToConnect $return_to_connect, string $failure_url, AuthStateManager $auth_state_manager, ConnectionErrorPage $connection_error_page ) {
		$this->shorthand             = $shorthand;
		$this->return_to_connect     = $return_to_connect;
		$this->failure_url           = $failure_url;
		$this->auth_state_manager    = $auth_state_manager;
		$this->connection_error_page = $connection_error_page;
	}

	public function define_redirect_page( Loader $loader ): void {
		$loader->add_action(
			'admin_post_shorthand_connect_start',
			$this,
			'render_page'
		);
	}

	public function get_url(): string {
		return admin_url( 'admin-post.php?action=shorthand_connect_start' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->connection_error_page->render( ConnectionFailure::permission_to_connect() );
			return;
		}

		if ( $this->auth_state_manager->requires_upgrade() ) {
			$this->connection_error_page->render( ConnectionFailure::upgrade_required() );
			return;
		}

		$target_url = $this->return_to_connect->get_callback_url();

		/* The user is redirected to an authorised link within the Shorthand app */
		$integration_url = $this->shorthand->get_integration_url( $target_url );
		wp_safe_redirect( $integration_url );

		exit;
	}
}
