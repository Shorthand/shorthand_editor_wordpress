<?php

namespace Shorthand\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Version {

	/**
	 * @var string
	 */
	const PLUGIN_NAME = 'The Shorthand Editor';
	/**
	 * @var string
	 */
	const PLUGIN_VERSION = '1.0.0';
	/**
	 * @var string
	 */
	const PLUGIN_URI = 'https://shorthand.com/shorthandforwordpress';
	/**
	 * @var string
	 */
	const AUTHOR_URI = 'https://shorthand.com/';

	public function get_plugin_name(): string {
		return self::PLUGIN_NAME;
	}

	public function get_plugin_version(): string {
		return self::PLUGIN_VERSION;
	}

	public function get_plugin_uri(): string {
		return self::PLUGIN_URI;
	}

	public function get_author_uri(): string {
		return self::AUTHOR_URI;
	}

	public function get_plugin_base_name(): string {
		return plugin_basename( THESHED_PLUGIN_FILE );
	}

	public function get_plugin_path( string $file = '' ): string {
		return plugin_dir_path( THESHED_PLUGIN_FILE ) . $file;
	}

	public function get_plugin_url( string $uri = '' ): string {
		return plugins_url( $uri, THESHED_PLUGIN_FILE );
	}

	public function is_dev_environment(): bool {
		$environment_type = wp_get_environment_type();
		return $environment_type === 'development' || $environment_type === 'local';
	}
}
