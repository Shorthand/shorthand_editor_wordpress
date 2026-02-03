<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Services\Options;
use Shorthand\Core\Version;

abstract class SettingsPage {

	/**
	 * @var \Shorthand\Services\Options
	 */
	protected $options;
	/**
	 * @var \Shorthand\Core\Version
	 */
	protected $version;
	/**
	 * @readonly
	 * @var string
	 */
	protected $page_title;
	/**
	 * @readonly
	 * @var mixed[]
	 */
	protected $option_groups;
	/**
	 * @readonly
	 * @var string
	 */
	protected $settings_page_slug;

	protected function __construct( Options $options, Version $version, string $page_title, array $option_groups, string $settings_page_slug ) {
		$this->options = $options;
		$this->version = $version;

		$this->page_title         = $page_title;
		$this->option_groups      = $option_groups;
		$this->settings_page_slug = $settings_page_slug;
	}

	public function render_partial( $args ) {
		include $this->version->get_plugin_path( 'assets/menu/' . $args['partial'] );
	}

	public function display_options_page() {
		$this->build_settings_sections();
		include $this->version->get_plugin_path( 'assets/menu/partials/options.php' );
	}

	abstract protected function build_settings_sections(): void;
}
