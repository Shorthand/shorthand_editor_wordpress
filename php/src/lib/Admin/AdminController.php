<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Shorthand\Core\Loader;
use Shorthand\Core\Version;

use Shorthand\Services\Options;
use Shorthand\Services\PostApi;
use Shorthand\Services\Permissions;
use Shorthand\Services\Cron;
use Shorthand\Services\Shorthand;

use Shorthand\Admin\Actions\ReturnToConnect;
use Shorthand\Admin\Actions\EditWithShorthand;
use Shorthand\Admin\Actions\RedirectToIntegration;
use Shorthand\Admin\Actions\PostPreview;

use WP_Post;

/**
 * Handles all admin-related functionality
 */
class AdminController {

	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;
	/**
	 * @var \Shorthand\Services\Cron
	 */
	private $cron;
	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;
	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;
	/**
	 * @var PostAPI
	 */
	private $post_api;
	/**
	 * @var \Shorthand\Services\Permissions
	 */
	private $permissions;
	/**
	 * @var string
	 */
	private $post_type;
	/**
	 * @var string
	 */
	private $settings_page_slug;

	public function __construct(
		Options $options,
		Shorthand $shorthand,
		Cron $cron,
		PostAPI $post_api,
		Permissions $permissions,
		Version $version,
		string $post_type
	) {
		$this->options     = $options;
		$this->shorthand   = $shorthand;
		$this->cron        = $cron;
		$this->version     = $version;
		$this->post_api    = $post_api;
		$this->permissions = $permissions;
		$this->post_type   = $post_type;
	}

	public function init(): void {
		$loader = new Loader();
		$this->setup_hooks( $loader );
		$loader->register();
	}

	private function setup_hooks( Loader $loader ): void {
		// Admin menu requires lower priority so that the menu is created before the post type adds items
		$loader->add_action( 'admin_menu', $this, 'add_admin_menu', 6 );

		// Admin initialization
		$loader->add_action( 'admin_init', $this, 'admin_init' );
	}

	public function add_admin_menu(): void {
		GeneralSettingsPage::register( $this->options, $this->version, 'shorthand-settings' );

		if ( $this->version->is_dev_environment() ) {
			DevSettingsPage::register( $this->options, $this->version, 'shorthand-settings-dev' );
		}
	}

	public function admin_init(): void {
		$loader = new Loader();

		$return_to_connect = new ReturnToConnect( $this->shorthand );

		$redirect_to_shorthand_story = new EditWithShorthand( $this->shorthand, $this->options, $this->post_api, $this->post_type );
		$redirect_to_integration     = new RedirectToIntegration( $this->shorthand, $return_to_connect, admin_url( 'plugins.php' ) );

		$post_preview = new PostPreview( $this->options, $this->post_api, $this->permissions, $this->version );

		$redirect_to_shorthand_story->define_redirect_and_return_pages( $loader );
		$redirect_to_integration->define_redirect_page( $loader );

		$return_to_connect->define_return_page( $loader );
		$post_preview->define_preview_page( $loader );

		$post = new Editor( $this->options, $this->shorthand, $this->cron, $this->version, $this->post_api, $post_preview, $redirect_to_shorthand_story, $this->post_type );
		$post->init( $loader );

		$loader->add_filter(
			'dashboard_glance_items',
			$this,
			'add_dashboard_glance_items'
		);

		$plugin_name = $this->version->get_plugin_base_name();
		$loader->add_filter(
			"plugin_action_links_{$plugin_name}",
			$this,
			'add_plugin_action_links'
		);

		$loader->add_filter(
			'allowed_redirect_hosts',
			$this,
			'add_allowed_redirect_hosts'
		);

		$loader->register();
	}

	public function add_dashboard_glance_items( array $items ): array {
		$story_count = wp_count_posts( $this->post_type )->publish;

		if ( $story_count > 0 ) {
			$url   = admin_url( 'edit.php?post_type=' . $this->post_type );
			$label = esc_html(
				sprintf(
				/* translators: One (a single) story; Multiple (more than one) stories */
					_n( '%s Story', '%s Stories', $story_count, 'the-shorthand-editor' ),
					$story_count
				)
			);
			$items[] = "<a href=\"{$url}\">{$label}</a>";
		}

		return $items;
	}


	public function add_plugin_action_links( array $links ): array {
		if ( $this->permissions->can_manage_shorthand() ) {
			$connect_url = admin_url( 'admin-post.php?action=shorthand_connect_start' );

			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $connect_url ),
				esc_html__( 'Connect to Shorthand&hellip;', 'the-shorthand-editor' )
			);
		}
		return $links;
	}

	public function add_allowed_redirect_hosts( array $hosts ): array {
		$api_url = wp_parse_url( $this->options->get_app_url() );
		if ( isset( $api_url['host'] ) ) {
			$hosts[] = $api_url['host'];
		}
		return $hosts;
	}

	public function get_settings_page_url(): string {
		return add_query_arg(
			array( 'page' => 'shorthand-settings' ),
			admin_url( 'options-general.php' )
		);
	}

	public function render_story_meta_box( WP_Post $post ): void {
		$story_id = get_post_meta( $post->ID, 'story_id', true );
		wp_nonce_field( 'shorthand_story_meta', 'shorthand_story_meta_nonce' );

		include $this->version->get_plugin_path( 'assets/admin/partials/story-meta-box.php' );
	}
}
