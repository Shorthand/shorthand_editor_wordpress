<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Core\Version;

class GeneralSettingsPage extends SettingsPage {

	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	public static function register( Options $options, Version $version, AuthStateManager $auth_state_manager, string $slug ): void {
		$instance = new self(
			$options,
			$version,
			$auth_state_manager,
			esc_html__( 'Shorthand Options', 'the-shorthand-editor' ),
			array( 'theshed-general-options-group' ),
			$slug
		);

		add_options_page(
			'Shorthand Options',
			'Shorthand',
			'manage_options',
			$instance->settings_page_slug,
			array( $instance, 'display_options_page' )
		);
	}

	protected function __construct( Options $options, Version $version, AuthStateManager $auth_state_manager, string $page_title, array $option_groups, string $settings_page_slug ) {
		parent::__construct( $options, $version, $page_title, $option_groups, $settings_page_slug );
		$this->auth_state_manager = $auth_state_manager;
	}

	protected function build_settings_sections(): void {
		add_settings_section(
			'shorthand_workspace_section',
			esc_html__( 'Workspace and Team', 'the-shorthand-editor' ),
			null,
			$this->settings_page_slug
		);

		if ( $this->options->is_verified() ) {
			add_settings_field(
				'shorthand_v2_token_org',
				esc_html__( 'Workspace', 'the-shorthand-editor' ),
				array( $this, 'render_partial' ),
				$this->settings_page_slug,
				'shorthand_workspace_section',
				array(
					'label_for' => 'shorthand_v2_token_org',
					'value'     => $this->options->get_token_org_name(),
					'partial'   => 'partials/option-token.php',
					'readonly'  => true,
					'link'      => $this->options->get_dashboard_url(),
					'link_text' => esc_html__( '&rarr; Shorthand Dashboard', 'the-shorthand-editor' ),
				)
			);

			if ( $this->options->get_token_type() != 'Organisation' ) {
				add_settings_field(
					'shorthand_v2_token_team',
					esc_html__( 'Team Name', 'the-shorthand-editor' ),
					array( $this, 'render_partial' ),
					$this->settings_page_slug,
					'shorthand_workspace_section',
					array(
						'label_for' => 'shorthand_v2_token_org',
						'value'     => $this->options->get_token_name(),
						'partial'   => 'partials/option-token.php',
						'readonly'  => true,
					)
				);
			}
		}

		add_settings_field(
			'shorthand_connection',
			esc_html__( 'Connection', 'the-shorthand-editor' ),
			array( $this, 'render_connection_button' ),
			$this->settings_page_slug,
			'shorthand_workspace_section'
		);

		add_settings_section(
			'shorthand_processing_section',
			esc_html__( 'Publishing and Post-processing', 'the-shorthand-editor' ),
			null,
			$this->settings_page_slug
		);

		add_settings_field(
			'shorthand_permalink',
			esc_html__( 'Permalink structure', 'the-shorthand-editor' ),
			array( $this, 'render_partial' ),
			$this->settings_page_slug,
			'shorthand_processing_section',
			array(
				'label_for' => 'shorthand_permalink',
				'value'     => $this->options->get_permalink(),
				'partial'   => 'partials/option-token.php',
			)
		);

		add_settings_field(
			'shorthand_css',
			esc_html__( 'Additional CSS', 'the-shorthand-editor' ),
			array( $this, 'render_partial' ),
			$this->settings_page_slug,
			'shorthand_processing_section',
			array(
				'label_for' => 'shorthand_css',
				'value'     => $this->options->get_post_css(),
				'partial'   => 'partials/option-text-area.php',
				'type'      => 'textarea',
				'rows'      => 10,
				'cols'      => 80,
			)
		);

		add_settings_field(
			'shorthand_regex_list',
			esc_html__( 'Post processing rules', 'the-shorthand-editor' ),
			array( $this, 'render_partial' ),
			$this->settings_page_slug,
			'shorthand_processing_section',
			array(
				'label_for' => 'shorthand_regex_list',
				'value'     => $this->options->get_post_regex_list(),
				'partial'   => 'partials/option-text-area.php',
				'type'      => 'textarea',
				'rows'      => 10,
				'cols'      => 80,
			)
		);
	}

	/**
	 * Render the Connect / Disconnect button in the Workspace section.
	 */
	public function render_connection_button(): void {
		$state = $this->auth_state_manager->get_state();

		if ( $state === AuthStateManager::STATE_CONNECTED ) {
			$disconnect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=shorthand_disconnect' ),
				'shorthand_disconnect'
			);
			?>
			<a href="<?php echo esc_url( $disconnect_url ); ?>"
			   class="button"
			   onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect from Shorthand? You will no longer be able to create or publish stories until you reconnect.', 'the-shorthand-editor' ) ); ?>');"
			><?php esc_html_e( 'Disconnect from Shorthand', 'the-shorthand-editor' ); ?></a>
			<?php
		} elseif ( $state === AuthStateManager::STATE_UPGRADE_REQUIRED ) {
			?>
			<a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>"
			   class="button button-primary"
			><?php esc_html_e( 'Update Plugin', 'the-shorthand-editor' ); ?></a>
			<p class="description">
				<?php esc_html_e( 'This version of the Shorthand plugin is no longer compatible. Please update to restore connectivity.', 'the-shorthand-editor' ); ?>
			</p>
			<?php
		} else {
			/* disconnected or invalid */
			$connect_url = admin_url( 'admin-post.php?action=shorthand_connect_start' );
			?>
			<a href="<?php echo esc_url( $connect_url ); ?>"
			   class="button button-primary"
			><?php esc_html_e( 'Connect to Shorthand', 'the-shorthand-editor' ); ?></a>
			<?php
			if ( $state === AuthStateManager::STATE_INVALID ) {
				?>
				<p class="description">
					<?php esc_html_e( 'Your previous connection has expired or been revoked. Please reconnect.', 'the-shorthand-editor' ); ?>
				</p>
				<?php
			}
		}
	}
}
