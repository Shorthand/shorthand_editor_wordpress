<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use WP_Error;

class ShorthandApiClient {

	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	/**
	 * @var \Shorthand\Services\ShorthandHttpTransport
	 */
	private $transport;

	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	public function __construct( Options $options, Version $version, AuthStateManager $auth_state_manager, ShorthandHttpTransport $transport ) {
		$this->options            = $options;
		$this->version            = $version;
		$this->auth_state_manager = $auth_state_manager;
		$this->transport          = $transport;
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|\WP_Error
	 */
	public function authed_request( string $url, string $method = 'GET', array $options = array(), ?array $body = null ) {
		$token = $this->options->get_v2_token();
		if ( '' === $token ) {
			return new WP_Error( 'settings', __( 'WordPress is not yet linked to a Shorthand workspace', 'the-shorthand-editor' ) );
		}

		$result = $this->request( $url, $method, $token, $options, $body );
		if ( is_wp_error( $result ) ) {
			$result->add( 'pretty', 'Shorthand is not available at this time.' );
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $url, string $method, ?string $token = null, array $options = array(), ?array $body = null ) {
		$ssl_verify      = ( defined( 'THESHED_NO_SSL_VERIFY' ) && THESHED_NO_SSL_VERIFY ) ? 0 : 1;
		$request_options = array_merge(
			array(
				'headers' => array(),
			),
			$options,
			array(
				'redirection' => false,
				'sslverify'   => $ssl_verify,
				'method'      => $method,
			)
		);

		$request_options['headers'] = array_merge(
			$request_options['headers'],
			$this->get_request_headers( $token )
		);

		if ( null !== $body ) {
			$request_options['body']                    = wp_json_encode( $body );
			$request_options['headers']['Content-Type'] = 'application/json';
		}

		$response = $this->transport->request( $url, $request_options );

		$this->auth_state_manager->intercept_response( $response );

		return $response;
	}

	/**
	 * Requests info from Shorthand about the given token.
	 * Returns the token info or WP_Error on failure.
	 *
	 * @return mixed[]|\WP_Error
	 */
	public function fetch_token_info( string $token ) {
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', 'An API token must be provided.' );
		}

		$response = $this->request(
			$this->options->get_api_url() . '/v2/token-info',
			'GET',
			$token
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error( 'status', "Verifying API token received HTTP status {$status_code}.", $status_code );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * @return string[]
	 */
	private function get_request_headers( ?string $token = null ): array {
		$user_agent = "WordPress/{$GLOBALS['wp_version']} {$this->version->get_plugin_name()}/{$this->version->get_plugin_version()}";
		$headers    = array(
			'user-agent' => $user_agent,
		);

		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Token ' . $token;
		}

		return $headers;
	}
}
