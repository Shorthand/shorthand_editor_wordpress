<?php

namespace Shorthand\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets WordPress's safe HTTP calls reach the local Shorthand API.
 *
 * `download_url()` goes through `wp_safe_remote_get()`, which refuses
 * private addresses, ports off its safe list, and unverifiable TLS. The
 * Docker environment fails all three: `host.docker.internal` is private,
 * ministack listens on 9443, and its certificate is self-signed. Each
 * exception below is scoped to the one host in `THESHED_API_URL`, and none
 * is registered outside local development.
 */
final class DevHttp {

	/**
	 * Registers the exceptions when `THESHED_NO_SSL_VERIFY` says this is local development.
	 */
	public static function register(): void {
		if ( ! defined( 'THESHED_NO_SSL_VERIFY' ) || ! THESHED_NO_SSL_VERIFY ) {
			return;
		}

		self::allow( defined( 'THESHED_API_URL' ) ? (string) THESHED_API_URL : '' );
	}

	/**
	 * Registers the exceptions for the host and port of one URL.
	 *
	 * @param string $api_url The Shorthand API base URL.
	 */
	public static function allow( string $api_url ): void {
		$api = wp_parse_url( $api_url );
		if ( ! is_array( $api ) || empty( $api['host'] ) ) {
			return;
		}

		$host = strtolower( $api['host'] );
		$port = isset( $api['port'] ) ? (int) $api['port'] : 0;

		add_filter(
			'http_request_host_is_external',
			static function ( $external, $request_host ) use ( $host ) {
				return strtolower( (string) $request_host ) === $host ? true : $external;
			},
			10,
			2
		);

		add_filter(
			'http_allowed_safe_ports',
			static function ( $ports, $request_host ) use ( $host, $port ) {
				if ( $port && strtolower( (string) $request_host ) === $host ) {
					$ports[] = $port;
				}
				return $ports;
			},
			10,
			2
		);

		add_filter(
			'https_ssl_verify',
			static function ( $verify, $url ) use ( $host ) {
				$request_host = wp_parse_url( (string) $url, PHP_URL_HOST );
				return strtolower( (string) $request_host ) === $host ? false : $verify;
			},
			10,
			2
		);
	}
}
