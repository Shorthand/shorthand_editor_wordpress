<?php

namespace Shorthand\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Plugin\PostType;
use Shorthand\Plugin\Templates;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\ShorthandApiClient;
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
	 * @var \Shorthand\Services\AuthStateManager
	 */
	protected $auth_state_manager;
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

	/**
	 * @var bool
	 */
	private $booted = false;

	public function __construct( ?Version $version = null, ?Permissions $permissions = null ) {
		$this->version     = $version ? $version : new Version();
		$this->permissions = $permissions ? $permissions : new Permissions();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->options = $this->create_options( $this->version );
		$this->options->init();

		$this->auth_state_manager = $this->create_auth_state_manager();

		$api_client      = $this->create_api_client( $this->options, $this->version, $this->auth_state_manager );
		$this->shorthand = $this->create_shorthand( $this->options, $this->version, $api_client );

		$this->token_manager = $this->create_token_manager( $this->options, $this->shorthand, $this->auth_state_manager );
		$this->token_manager->init();

		$this->post_type = $this->create_post_type( $this->options->get_permalink(), $this->version );
		$this->post_type->init();

		$this->templates = $this->create_templates( $this->post_type->post_type, $this->options, $this->version );
		$this->templates->init();

		$this->cron = $this->create_cron( $this );
		$this->cron->init();

		$this->booted = true;
	}

	protected function create_options( Version $version ): Options {
		return new Options( $version );
	}

	protected function create_auth_state_manager(): AuthStateManager {
		return new AuthStateManager( $this->version );
	}

	protected function create_api_client( Options $options, Version $version, AuthStateManager $auth_state_manager ): ShorthandApiClient {
		return new ShorthandApiClient( $options, $version, null, $auth_state_manager );
	}

	protected function create_shorthand( Options $options, Version $version, ?ShorthandApiClient $api_client = null ): Shorthand {
		return new Shorthand( $options, $version, $api_client );
	}

	protected function create_token_manager( Options $options, Shorthand $shorthand, AuthStateManager $auth_state_manager ): TokenManager {
		return new TokenManager( $options, $shorthand, $auth_state_manager );
	}

	protected function create_post_type( string $permalink, Version $version ): PostType {
		return new PostType( $permalink, $version );
	}

	protected function create_templates( string $post_type, Options $options, Version $version ): Templates {
		return new Templates( $post_type, $options, $version );
	}

	protected function create_cron( Dependencies $dependencies ): Cron {
		return new Cron( $dependencies );
	}

	public function get_version(): Version {
		return $this->version;
	}

	public function get_permissions(): Permissions {
		return $this->permissions;
	}

	public function get_post_type(): PostType {
		$this->boot();
		return $this->post_type;
	}

	public function get_templates(): Templates {
		$this->boot();
		return $this->templates;
	}

	public function get_post_api(): PostAPI {
		$this->boot();
		if ( ! isset( $this->post_api ) ) {
			$this->post_api = new PostAPI( $this->shorthand, $this->get_options(), $this->get_permissions(), $this->get_post_type()->post_type, null, $this->get_auth_state_manager() );
		}
		return $this->post_api;
	}

	public function get_admin(): AdminController {
		$this->boot();
		if ( ! isset( $this->admin ) ) {
			$this->admin = new AdminController(
				$this->get_options(),
				$this->get_shorthand(),
				$this->get_cron(),
				$this->get_post_api(),
				$this->get_permissions(),
				$this->version,
				$this->get_post_type()->post_type,
				$this->get_auth_state_manager()
			);
			$this->admin->init();
		}
		return $this->admin;
	}

	public function get_options(): Options {
		$this->boot();
		return $this->options;
	}

	public function get_shorthand(): Shorthand {
		$this->boot();
		return $this->shorthand;
	}

	public function get_token_manager(): TokenManager {
		$this->boot();
		return $this->token_manager;
	}

	public function get_auth_state_manager(): AuthStateManager {
		$this->boot();
		return $this->auth_state_manager;
	}

	public function get_cron(): Cron {
		$this->boot();
		return $this->cron;
	}
}
