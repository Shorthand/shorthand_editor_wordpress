<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Admin\AdminGateway;
use Shorthand\Services\Shorthand;

class ConnectionCompletionService {

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	/**
	 * @var \Shorthand\Admin\AdminGateway
	 */
	private $admin_gateway;

	public function __construct( Shorthand $shorthand, AdminGateway $admin_gateway ) {
		$this->shorthand     = $shorthand;
		$this->admin_gateway = $admin_gateway;
	}

	public function complete( string $token, int $post_id = 0 ): string {
		$this->shorthand->connect( $token );

		if ( $post_id > 0 ) {
			$edit_post_link = $this->admin_gateway->get_edit_post_link( $post_id );
			if ( $edit_post_link ) {
				return $edit_post_link;
			}
		}

		return $this->admin_gateway->get_settings_page_url();
	}
}
