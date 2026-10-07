<?php
/**
 * Boots the WordPress core test library with the plugin loaded.
 *
 * WP_TESTS_DIR is set by wp-env inside its containers. Run with:
 *   pnpm test:php:integration
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir || ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run this suite inside wp-env: pnpm test:php:integration\n" );
	exit( 1 );
}

$autoload = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "Missing vendor/. Run: composer install -d php/tests/integration\n" );
	exit( 1 );
}
require_once $autoload;

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/the-shorthand-editor/the-shorthand-editor.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
