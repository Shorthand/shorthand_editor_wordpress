<?php

namespace Shorthand;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Plugin\Dependencies;
use Shorthand\Admin\AdminController;
use Shorthand\Services\Cron;
use Shorthand\Services\Options;
use Shorthand\Services\StoryKses;
use Shorthand\Plugin\PostType;

class Plugin {

	/**
	 * @var \Shorthand\Plugin\Dependencies
	 */
	private $dependencies;
	/**
	 * @var \Shorthand\Services\Cron
	 */
	private $cron;
	/**
	 * @var \Shorthand\Plugin\PostType
	 */
	private $post_type;

	/**
	 * @var \Shorthand\Admin\AdminController|null
	 */
	private $admin;

	/**
	 * @var \Shorthand\Services\StoryKses
	 */
	private $story_kses;

	public function __construct() {
		$this->dependencies = new Dependencies();
	}

	public function init() {
		$config_path = plugin_dir_path( __FILE__ ) . 'Plugin/sh-config.php';
		if ( file_exists( $config_path ) ) {
			include $config_path;
		}

		register_activation_hook( THESHED_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( THESHED_PLUGIN_FILE, array( $this, 'deactivate' ) );

		$this->post_type = $this->dependencies->get_post_type();

		$this->story_kses = new StoryKses();
		$this->story_kses->init();

		if ( is_admin() ) {
			$this->admin = $this->dependencies->get_admin();
		}

		$this->cron = $this->dependencies->get_cron();
	}

	public function activate() {
		$options = $this->dependencies->get_options();
		$options->activate_plugin();

		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}
}
