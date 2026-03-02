<?php
/**
 * Plugin Name: The Shorthand Editor
 * Plugin URI: https://shorthand.com/shorthand-for-wordpress
 * Version: 1.0.0
 * Description: Build rich, compelling content with Shorthand, the premier story-telling experience.
 * Repository URI: https://github.com/Shorthand/shorthand_editor_wordpress
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Author: Shorthand
 * Author URI: https://shorthand.com/
 * Requires at least: 6.0
 * Tested up to: 6.9.1
 * Requires PHP: 7.4
 * Text Domain: the-shorthand-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THESHED_PLUGIN_FILE', __FILE__ );

spl_autoload_register( 'theshed_class_autoloader' );

add_action( 'plugins_loaded', 'theshed_run' );


// Autoloader - Shorthand namespace is in the lib directory.
function theshed_class_autoloader( $class ) {
	$prefix      = 'Shorthand\\';
	$deps_prefix = 'Vendor\\';
	$base_dir    = __DIR__ . '/lib/';
	$len         = strlen( $prefix );

	if ( strncmp( $class, $prefix, $len ) !== 0 ) {
		return;
	}

	$relative_class = (string) substr( $class, $len );

	if ( strncmp( $relative_class, $deps_prefix, strlen( $deps_prefix ) ) !== 0 ) {
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	} else {
		$jwt_dep_prefix     = 'Vendor\\Firebase\\JWT\\';
		$jwt_dep_prefix_len = strlen( $jwt_dep_prefix );
		if ( strncmp( $relative_class, $jwt_dep_prefix, $jwt_dep_prefix_len ) === 0 ) {
			$file = __DIR__ . '/vendor_prefixed/firebase/php-jwt/src/' . substr( $relative_class, $jwt_dep_prefix_len ) . '.php';
		} else {
			// This is an unknown dependency namespace.
			return;
		}
	}

	if ( ! is_readable( $file ) ) {
		return;
	}

	require_once $file;
}

function theshed_run() {
	$plugin = new Shorthand\Plugin();
	$plugin->init();
}
