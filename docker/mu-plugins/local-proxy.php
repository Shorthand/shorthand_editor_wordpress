<?php
/**
 * Plugin Name: Local reverse proxy support
 * Description: Serves WordPress under the proxied HTTPS origin. wp-env pins WP_SITEURL to its own port, so the site and home URLs are taken from the forwarded request instead.
 */

if ( ! isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || 'https' !== $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	return;
}

$_SERVER['HTTPS'] = 'on';

$local_proxy_host   = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
$local_proxy_prefix = rtrim( $_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '', '/' );
$local_proxy_origin = 'https://' . $local_proxy_host . $local_proxy_prefix;

$local_proxy_url = static function () use ( $local_proxy_origin ) {
	return $local_proxy_origin;
};

// Priority 20 runs after WordPress applies the WP_SITEURL and WP_HOME constants.
add_filter( 'option_siteurl', $local_proxy_url, 20 );
add_filter( 'option_home', $local_proxy_url, 20 );
