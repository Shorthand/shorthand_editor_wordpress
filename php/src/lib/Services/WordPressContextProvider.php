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
	 * Describes this site to the Shorthand API.
	 *
	 * The context travels as JSON, so every value must be plain text.  That
	 * needs care with the site name: WordPress escapes `blogname` on save and
	 * `get_bloginfo()` returns the stored value untouched, so the entities
	 * would otherwise reach Shorthand verbatim (PLA-2464).
	 *
	 * @return array<string, string>
	 */
	public function get_context(): array {
		return array(
			'wp_version'     => $GLOBALS['wp_version'],
			'plugin_name'    => $this->version->get_plugin_name(),
			'plugin_version' => $this->version->get_plugin_version(),
			'site_name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'       => get_site_url(),
			'site_rest_url'  => get_rest_url(),
		);
	}
}
