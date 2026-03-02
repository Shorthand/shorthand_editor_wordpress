<?php

namespace Shorthand;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Core\Version;
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

	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

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

		$this->options   = $this->dependencies->get_options();
		$this->version   = $this->dependencies->get_version();
		$this->post_type = $this->dependencies->get_post_type();

		$this->story_kses = new StoryKses();
		$this->story_kses->init();

		if ( is_admin() ) {
			$this->admin = $this->dependencies->get_admin();
		}

		$this->cron = $this->dependencies->get_cron();

		$loader = new Loader();
		$loader->add_filter( 'pre_set_site_transient_update_plugins', $this, 'check_for_updates' );
		$loader->add_filter( 'plugins_api', $this, 'plugin_info', 10, 3 );
		if ( defined( 'THESHED_BLOCK_UPGRADE' ) && THESHED_BLOCK_UPGRADE ) {
			$loader->add_filter( 'upgrader_pre_install', $this, 'block_upgrade', 10, 2 );
		}
		$loader->register();
	}

	public function activate() {
		$options = $this->dependencies->get_options();
		$options->activate_plugin();

		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	public function check_for_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		delete_transient( 'theshed_update_info' );

		$remote = $this->get_remote_update_info();
		if ( ! $remote ) {
			return $transient;
		}

		$plugin_slug     = $this->version->get_plugin_slug();
		$plugin_basename = $this->version->get_plugin_base_name();

		$icons = (array) ( $remote->icons ?? array() );

		if ( version_compare( $this->version->get_plugin_version(), $remote->version, '<' ) ) {
			$transient->response[ $plugin_basename ] = (object) array(
				'slug'         => $plugin_slug,
				'plugin'       => $plugin_basename,
				'new_version'  => $remote->version,
				'url'          => $remote->homepage ?? '',
				'package'      => $remote->download_url ?? '',
				'tested'       => $remote->tested ?? '',
				'requires'     => $remote->requires ?? '',
				'requires_php' => $remote->requires_php ?? '',
				'icons'        => $icons,
			);
		} else {
			$transient->no_update[ $plugin_basename ] = (object) array(
				'slug'        => $plugin_slug,
				'plugin'      => $plugin_basename,
				'new_version' => $remote->version,
				'url'         => $remote->homepage ?? '',
				'package'     => $remote->download_url ?? '',
				'icons'       => $icons,
			);
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		$plugin_slug = $this->version->get_plugin_slug();

		if ( ! isset( $args->slug ) || $args->slug !== $plugin_slug ) {
			return $result;
		}

		$remote = $this->get_remote_update_info();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'           => $remote->name ?? $this->version->get_plugin_name(),
			'slug'           => $plugin_slug,
			'version'        => $remote->version,
			'author'         => $remote->author ?? '',
			'author_profile' => $remote->author_profile ?? '',
			'homepage'       => $remote->homepage ?? '',
			'requires'       => $remote->requires ?? '',
			'tested'         => $remote->tested ?? '',
			'requires_php'   => $remote->requires_php ?? '',
			'download_link'  => $remote->download_url ?? '',
			'sections'       => (array) ( $remote->sections ?? array() ),
			'banners'        => (array) ( $remote->banners ?? array() ),
			'icons'          => (array) ( $remote->icons ?? array() ),
		);
	}

	public function block_upgrade( $response, $hook_extra ) {
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->version->get_plugin_base_name() ) {
			return new \WP_Error( 'upgrade_blocked', __( 'Plugin upgrades are disabled in this environments.', 'the-shorthand-editor' ) );
		}
		return $response;
	}

	private function get_remote_update_info() {
		$update_url = $this->options->get_update_url();

		$transient_key = 'theshed_update_info';
		$cached        = get_transient( $transient_key );
		if ( $cached !== false ) {
			return $cached === 'error' ? false : $cached;
		}

		error_log( '## UPDATE CHECK ## ' . $update_url );
		$response = wp_remote_get( $update_url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			error_log( ' ## UPDATE CHECK FAILED ## ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) ) );
			set_transient( $transient_key, 'error', HOUR_IN_SECONDS );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );
		if ( ! is_object( $data ) || ! isset( $data->version ) ) {
			set_transient( $transient_key, 'error', HOUR_IN_SECONDS );
			return false;
		}

		set_transient( $transient_key, $data, 12 * HOUR_IN_SECONDS );

		return $data;
	}
}
