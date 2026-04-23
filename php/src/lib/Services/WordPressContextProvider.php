<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;

class WordPressContextProvider {

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	public function __construct( Version $version ) {
		$this->version = $version;
	}

	/**
	 * @return string[]
	 */
	public function get_context(): array {
		return array(
			'wp_version'     => $GLOBALS['wp_version'],
			'plugin_name'    => $this->version->get_plugin_name(),
			'plugin_version' => $this->version->get_plugin_version(),
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => get_site_url(),
			'site_rest_url'  => get_rest_url(),
		);
	}
}
