<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\Options;
use Shorthand\Core\Version;

class GeneralSettingsPage extends SettingsPage {

	public static function register( Options $options, Version $version, string $slug ): void {
		$instance = new self(
			$options,
			$version,
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
}
