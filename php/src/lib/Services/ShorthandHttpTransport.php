<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShorthandHttpTransport {

	/**
	 * @param array<string, mixed> $request_options
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $url, array $request_options ) {
		return wp_remote_request( $url, $request_options ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_request_wp_remote_request
	}
}
