<?php
/**
 * Plugin Name: The Shorthand Editor
 * Plugin URI: https://shorthand.com/products/shorthand-for-wordpress
 * Version: 1.0.8
 * Description: Build rich, compelling content with Shorthand, the premier story-telling experience.
 * Repository URI: https://github.com/Shorthand/shorthand_editor_wordpress
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Author: Shorthand
 * Author URI: https://shorthand.com/
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Text Domain: the-shorthand-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THESHED_PLUGIN_FILE', __FILE__ );

spl_autoload_register( 'theshed_class_autoloader' );

register_activation_hook( __FILE__, 'theshed_activate' );
register_deactivation_hook( __FILE__, 'theshed_deactivate' );

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

/**
 * Creates the plugin and runs its activation routine.
 */
function theshed_activate() {
	theshed_create_plugin()->activate();
}

/**
 * Creates the plugin and runs its deactivation routine.
 */
function theshed_deactivate() {
	theshed_create_plugin()->deactivate();
}

/**
 * Creates the plugin and initialises its runtime hooks.
 */
function theshed_run() {
	theshed_create_plugin()->init();
}

/**
 * Creates the plugin instance.
 *
 * @return Shorthand\Plugin
 */
function theshed_create_plugin() {
	$dependencies = new Shorthand\Plugin\Dependencies(
		new Shorthand\Core\Version(),
		new Shorthand\Services\Permissions()
	);

	return new Shorthand\Plugin(
		$dependencies,
		new Shorthand\Services\StoryKses()
	);
}
