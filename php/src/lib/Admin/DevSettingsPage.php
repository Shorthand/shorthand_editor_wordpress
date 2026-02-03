<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\Options;
use Shorthand\Core\Version;

class DevSettingsPage extends SettingsPage {

	public static function register( Options $options, Version $version, string $slug ): void {
		$instance = new self(
			$options,
			$version,
			esc_html__( 'Shorthand Options (Dev)', 'the-shorthand-editor' ),
			array( 'theshed-dev-options-group' ),
			$slug
		);

		add_options_page(
			'Shorthand Options (Dev)',
			'Shorthand (Dev)',
			'manage_options',
			$instance->settings_page_slug,
			array( $instance, 'display_options_page' )
		);
	}

	protected function build_settings_sections(): void {
		add_settings_section(
			'theshed-dev-section',
			esc_html__( 'Shorthand Server', 'the-shorthand-editor' ),
			null,
			$this->settings_page_slug
		);

		add_settings_field(
			'shorthand_app_url',
			'Shorthand website URL',
			array( $this, 'render_partial' ),
			$this->settings_page_slug,
			'theshed-dev-section',
			array(
				'label_for' => 'shorthand_app_url',
				'value'     => $this->options->get_app_url(),
				'partial'   => 'partials/option-token.php',
			)
		);

		add_settings_field(
			'shorthand_api_url',
			'Shorthand API URL',
			array( $this, 'render_partial' ),
			$this->settings_page_slug,
			'theshed-dev-section',
			array(
				'label_for' => 'shorthand_api_url',
				'value'     => $this->options->get_api_url(),
				'partial'   => 'partials/option-token.php',
			)
		);
	}
}
