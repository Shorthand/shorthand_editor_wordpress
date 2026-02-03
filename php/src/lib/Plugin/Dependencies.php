<?php

namespace Shorthand\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Plugin\PostType;
use Shorthand\Plugin\Templates;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\TokenManager;
use Shorthand\Admin\AdminController;
use Shorthand\Services\Cron;

class Dependencies {

	/**
	 * @var \Shorthand\Core\Version
	 */
	protected $version;
	/**
	 * @var \Shorthand\Plugin\PostType
	 */
	protected $post_type;
	/**
	 * @var \Shorthand\Plugin\Templates
	 */
	protected $templates;
	/**
	 * @var \Shorthand\Services\Options
	 */
	protected $options;
	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	protected $shorthand;
	/**
	 * @var \Shorthand\Services\TokenManager
	 */
	protected $token_manager;
	/**
	 * @var \Shorthand\Services\Permissions
	 */
	protected $permissions;
	/**
	 * @var \Shorthand\Services\PostAPI
	 */
	protected $post_api;
	/**
	 * @var \Shorthand\Admin\AdminController
	 */
	protected $admin;
	/**
	 * @var \Shorthand\Services\Cron
	 */
	protected $cron;

	public function __construct() {
		$this->version     = new Version();
		$this->permissions = new Permissions();

		$this->options = new Options( $this->version );
		$this->options->init();

		$this->shorthand = new Shorthand( $this->options, $this->version );

		$this->token_manager = new TokenManager( $this->options, $this->shorthand );
		$this->token_manager->init();

		$this->post_type = new PostType( $this->options->get_permalink(), $this->version );
		$this->post_type->init();

		$this->templates = new Templates( $this->post_type->post_type, $this->options, $this->version );
		$this->templates->init();

		$this->cron = new Cron( $this );
		$this->cron->init();
	}

	public function get_version(): Version {
		return $this->version;
	}

	public function get_permissions(): Permissions {
		return $this->permissions;
	}

	public function get_post_type(): PostType {
		return $this->post_type;
	}

	public function get_templates(): Templates {
		return $this->templates;
	}

	public function get_post_api(): PostAPI {
		if ( ! isset( $this->post_api ) ) {
			$this->post_api = new PostAPI( $this->shorthand, $this->get_options(), $this->get_permissions(), $this->get_post_type()->post_type );
		}
		return $this->post_api;
	}

	public function get_admin(): AdminController {
		if ( ! isset( $this->admin ) ) {
			$this->admin = new AdminController(
				$this->get_options(),
				$this->get_shorthand(),
				$this->get_cron(),
				$this->get_post_api(),
				$this->get_permissions(),
				$this->version,
				$this->get_post_type()->post_type
			);
			$this->admin->init();
		}
		return $this->admin;
	}

	public function get_options(): Options {
		return $this->options;
	}

	public function get_shorthand(): Shorthand {
		return $this->shorthand;
	}

	private function get_token_manager(): TokenManager {
		return $this->token_manager;
	}

	public function get_cron(): Cron {
		return $this->cron;
	}
}
