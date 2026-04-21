<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Shorthand\Core\Loader;
use Shorthand\Core\Version;

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\PostApi;
use Shorthand\Services\Permissions;
use Shorthand\Services\Cron;
use Shorthand\Services\Shorthand;

use Shorthand\Admin\Actions\ReturnToConnect;
use Shorthand\Admin\Actions\EditWithShorthand;
use Shorthand\Admin\Actions\RedirectToIntegration;
use Shorthand\Admin\Actions\PostPreview;
use Shorthand\Admin\Actions\ConnectionCompletionService;
use Shorthand\Admin\Actions\StoryEditorLinkBuilder;
use Shorthand\Admin\Actions\StoryReturnHandler;

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
	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	public function __construct(
		Options $options,
		Shorthand $shorthand,
		Cron $cron,
		PostAPI $post_api,
		Permissions $permissions,
		Version $version,
		string $post_type,
		AuthStateManager $auth_state_manager
	) {
		$this->settings_page_slug = 'theshed-settings';

		$this->options            = $options;
		$this->shorthand          = $shorthand;
		$this->cron               = $cron;
		$this->version            = $version;
		$this->post_api           = $post_api;
		$this->permissions        = $permissions;
		$this->post_type          = $post_type;
		$this->auth_state_manager = $auth_state_manager;
	}

	public function init(): void {
		$loader = new Loader();
		$this->setup_hooks( $loader );
		$loader->register();
	}

	private function setup_hooks( Loader $loader ): void {
		/* Initialise additional menu items. A lower priority is required so that the menu is created before items are added. */
		$loader->add_action( 'admin_menu', $this, 'add_admin_menu', 6 );

		/* Initialise the editor, preview and Shorthand redirection. */
		$loader->add_action( 'admin_init', $this, 'admin_init' );

		/*
		 * Register connect-flow admin-post hooks at plugins_loaded time.
		 *
		 * admin-post.php fires admin_init then immediately checks
		 * has_action("admin_post_{$action}") — if registration were deferred
		 * to admin_init and anything interrupted that callback before
		 * $loader->register() ran, the check would fail and WordPress would
		 * emit wp_die('', 400), producing a blank error screen on return
		 * from the Shorthand connect flow.
		 */
		$admin_gateway     = new AdminGateway( $this->settings_page_slug );
		$return_to_connect = new ReturnToConnect(
			$this->shorthand,
			new ConnectionCompletionService( $this->shorthand, $admin_gateway )
		);
		$return_to_connect->define_return_page( $loader );

		$redirect_to_integration = new RedirectToIntegration(
			$this->shorthand,
			$return_to_connect,
			admin_url( 'plugins.php' ),
			$this->auth_state_manager
		);
		$redirect_to_integration->define_redirect_page( $loader );

		/* Handle workspace disconnection. */
		$loader->add_action( 'admin_post_shorthand_disconnect', $this, 'handle_disconnect' );

		/* Show a notice on admin pages if the plugin is not in a connected state. */
		$loader->add_action( 'admin_notices', $this, 'render_auth_notice' );
		$loader->add_action( 'wp_ajax_shorthand_dismiss_auth_notice', $this, 'dismiss_auth_notice' );
	}

	public function add_admin_menu(): void {
		GeneralSettingsPage::register( $this->options, $this->version, $this->auth_state_manager, $this->settings_page_slug );
	}

	public function admin_init(): void {
		$loader        = new Loader();
		$admin_gateway = new AdminGateway( $this->settings_page_slug );

		$story_editor_link_builder = new StoryEditorLinkBuilder();
		$story_return_handler      = new StoryReturnHandler(
			$this->post_api,
			$this->shorthand,
			$admin_gateway,
			$story_editor_link_builder,
			$this->post_type
		);

		$redirect_to_shorthand_story = new EditWithShorthand(
			$this->shorthand,
			$this->options,
			$this->post_api,
			$this->post_type,
			$story_return_handler,
			$story_editor_link_builder,
			$this->auth_state_manager
		);

		$post_preview = new PostPreview( $this->options, $this->post_api, $this->permissions, $this->version, $this->auth_state_manager );

		$redirect_to_shorthand_story->define_redirect_and_return_pages( $loader );
		$post_preview->define_preview_page( $loader );

		$post = new Editor( $this->options, $this->shorthand, $this->cron, $this->version, $this->post_api, $post_preview, $redirect_to_shorthand_story, $this->post_type, $this->auth_state_manager );
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
			array( 'page' => $this->settings_page_slug ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Handle the "Disconnect from Shorthand" action.
	 *
	 * Clears the API token, which triggers TokenManager to clear token_info
	 * and set the auth state to disconnected.
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'the-shorthand-editor' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'shorthand_disconnect' );

		update_option( 'shorthand_v2_token', '' );

		wp_safe_redirect( $this->get_settings_page_url() );
		exit;
	}

	/**
	 * Display an admin notice when the plugin is not in a connected state.
	 *
	 * The notice is shown on all admin pages and varies by auth state:
	 *
	 * - `never_connected`   – welcomes the user and invites them to connect.
	 * - `disconnected`      – invites the user to reconnect after an
	 *                         intentional disconnect.
	 * - `invalid`           – explains the connection has expired.
	 * - `upgrade_required`  – asks the user to update the plugin.
	 *
	 * Each user may dismiss the notice.  A state change causes the notice to
	 * reappear for all users by comparing the dismissal timestamp with the
	 * state's `changed_at` timestamp.
	 */
	public function render_auth_notice(): void {
		if ( $this->auth_state_manager->is_connected() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dismissed_at = (int) get_user_meta( get_current_user_id(), 'shorthand_auth_notice_dismissed_at', true );
		if ( $dismissed_at > 0 && $dismissed_at >= $this->auth_state_manager->get_changed_at() ) {
			return;
		}

		$state = $this->auth_state_manager->get_state();

		switch ( $state ) {
			case AuthStateManager::STATE_UPGRADE_REQUIRED:
				$notice_class = 'notice-error';
				$message      = __( 'This version of the Shorthand plugin is no longer compatible with Shorthand. Please update the plugin to restore functionality.', 'the-shorthand-editor' );
				$action_url   = self_admin_url( 'plugins.php' );
				$action_label = __( 'Go to Plugins', 'the-shorthand-editor' );
				break;

			case AuthStateManager::STATE_INVALID:
				$notice_class = 'notice-warning';
				$message      = __( 'Your Shorthand connection has expired or been revoked. Please reconnect your workspace.', 'the-shorthand-editor' );
				$action_url   = admin_url( 'admin-post.php?action=shorthand_connect_start' );
				$action_label = __( 'Connect to Shorthand', 'the-shorthand-editor' );
				break;

			case AuthStateManager::STATE_DISCONNECTED:
				$notice_class = 'notice-info';
				$message      = __( 'Your Shorthand workspace is disconnected. Reconnect to resume creating and publishing stories.', 'the-shorthand-editor' );
				$action_url   = admin_url( 'admin-post.php?action=shorthand_connect_start' );
				$action_label = __( 'Connect to Shorthand', 'the-shorthand-editor' );
				break;

			default: /* never_connected */
				$notice_class = 'notice-info';
				$message      = __( 'Welcome to Shorthand! Connect your workspace to start creating and publishing stories.', 'the-shorthand-editor' );
				$action_url   = admin_url( 'admin-post.php?action=shorthand_connect_start' );
				$action_label = __( 'Connect to Shorthand', 'the-shorthand-editor' );
				break;
		}

		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible" id="shorthand-auth-notice">
			<p>
				<strong><?php esc_html_e( 'Shorthand', 'the-shorthand-editor' ); ?></strong> &mdash;
				<?php echo esc_html( $message ); ?>
				<a href="<?php echo esc_url( $action_url ); ?>">
					<?php echo esc_html( $action_label ); ?> &rarr;
				</a>
			</p>
		</div>
		<script>
		jQuery( document ).on( 'click', '#shorthand-auth-notice .notice-dismiss', function() {
			jQuery.post( ajaxurl, {
				action: 'shorthand_dismiss_auth_notice',
				nonce: '<?php echo esc_js( wp_create_nonce( 'shorthand_dismiss_auth_notice' ) ); ?>'
			} );
		} );
		</script>
		<?php
	}

	/**
	 * AJAX handler: dismiss the auth state notice for the current user.
	 *
	 * Stores the auth state's `changed_at` timestamp so the notice reappears
	 * only when the state changes again.
	 */
	public function dismiss_auth_notice(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'shorthand_dismiss_auth_notice' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		update_user_meta(
			get_current_user_id(),
			'shorthand_auth_notice_dismissed_at',
			$this->auth_state_manager->get_changed_at()
		);
		wp_die();
	}
}
