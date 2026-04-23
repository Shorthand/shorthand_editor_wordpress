<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Services\Options;
use WP_Error;
use Exception;

use Shorthand\Vendor\Firebase\JWT\JWT;

class Shorthand {

	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;
	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;
	/**
	 * @var \Shorthand\Services\ShorthandApiClient
	 */
	private $api_client;
	/**
	 * @var \Shorthand\Services\WordPressContextProvider
	 */
	private $context_provider;

	public function __construct(
		Options $options,
		Version $version,
		ShorthandApiClient $api_client,
		WordPressContextProvider $context_provider
	) {
		$this->options          = $options;
		$this->version          = $version;
		$this->api_client       = $api_client;
		$this->context_provider = $context_provider;
	}

	/**
	 * Create a URL to connect to a workspace that returns to the given target
	 *
	 * @param string $target_url a URL
	 * @return string
	 */
	public function get_integration_url( string $target_url ): string {
		/* create return path to settings page */
		$url = get_rest_url();
		$this->refresh_next_keys();

		$identity = $this->sign_identity_for_connection( $target_url ); // 15 mimnutes

		$args = array(
			'type'  => 'wordpress',
			'token' => rawurlencode( $identity ),
		);

		$url = $this->options->get_app_url() . '/integration/v2/connect';
		return add_query_arg( $args, $url );
	}

	private function refresh_next_keys(): void {
		$key_pair = sodium_crypto_sign_keypair();

		$random_key_id = base64_encode( random_bytes( 16 ) );

		$private_key = sodium_crypto_sign_secretkey( $key_pair );
		$public_key  = JWT::urlsafeB64Encode( sodium_crypto_sign_publickey( $key_pair ) );

		$signing_key = array(
			'kty' => 'OKP',
			'crv' => 'Ed25519',
			'alg' => 'EdDSA',
			'd'   => base64_encode( $private_key ),
		);

		$verifying_key = array(
			'kty' => 'OKP',
			'kid' => $random_key_id,
			'crv' => 'Ed25519',
			'x'   => $public_key,
		);

		$this->options->set_v2_next_signing_and_verifying_keys( $signing_key, $verifying_key );
	}

	public function connect( $token ): void {
		$tks                             = explode( '.', $token );
		[$headb64, $bodyb64, $cryptob64] = $tks;
		$payload                         = JWT::jsonDecode( JWT::urlsafeB64Decode( $bodyb64 ) );

		if ( null === $payload->nonce ) {
			wp_die(
				esc_html__( 'Could not connect to Shorthand because the credentials provided were invalid. Please try connecting again. If this problem persists, contact Shorthand support.', 'the-shorthand-editor' ),
				esc_html__( 'Connection Failed', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( admin_url( 'admin-post.php?action=shorthand_connect_start' ) ),
					'link_text' => esc_html__( 'Try connecting again', 'the-shorthand-editor' ),
				)
			);
		}

		// https://darutk.medium.com/illustrated-dpop-oauth-access-token-security-enhancement-801680d761ff
		// The dpop response is a separate JWT to the token.
		$dpop = $this->sign_dpop_for_connection( $payload->nonce );

		$url  = $this->options->get_api_url() . '/v2/connect?type=wordpress'; /* phpcs:ignore WordPress.WP.CapitalPDangit */
		$body = array(
			'token'             => $token,
			'dpop'              => $dpop,
			'wordpress_context' => $this->context_provider->get_context(),
		);

		$response = $this->api_client->request( $url, 'POST', null, array(), $body );
		if ( is_wp_error( $response ) ) {
			wp_die(
				esc_html__( 'Could not reach the Shorthand server. Please check your network connection and try again. If this problem persists, contact your site administrator.', 'the-shorthand-editor' ),
				esc_html__( 'Connection Failed', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( admin_url( 'admin-post.php?action=shorthand_connect_start' ) ),
					'link_text' => esc_html__( 'Try connecting again', 'the-shorthand-editor' ),
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->die_on_connect_error( $status_code );
		}

		$body      = json_decode( wp_remote_retrieve_body( $response ), true );
		$api_token = $body['apiToken'];

		$this->options->update_v2_signing_keys();
		update_option( 'shorthand_v2_token', $api_token );
	}


	/**
	 * Terminate the connect flow with a status-code-aware wp_die screen.
	 *
	 * Translates the HTTP status returned by the Shorthand API into a
	 * user-friendly message with an appropriate recovery action:
	 * reauthorise on 401/403, update the plugin on 426, retry on 5xx,
	 * or a generic retry screen for any other non-success response.
	 *
	 * @param int $status_code HTTP status code returned by the Shorthand API.
	 */
	private function die_on_connect_error( int $status_code ): void {
		$retry_link = array(
			'link_url'  => esc_url( admin_url( 'admin-post.php?action=shorthand_connect_start' ) ),
			'link_text' => esc_html__( 'Try connecting again', 'the-shorthand-editor' ),
		);

		$status_detail = array(
			'message' => sprintf(
				/* translators: %d: HTTP status code returned by the Shorthand API. */
				esc_html__( 'The request returned HTTP status code %d.', 'the-shorthand-editor' ),
				$status_code
			),
		);

		if ( 401 === $status_code || 403 === $status_code ) {
			wp_die(
				esc_html__( 'Shorthand rejected the connection request. The authorization may have expired or been revoked. Please try connecting again.', 'the-shorthand-editor' ),
				esc_html__( 'Authorization Failed', 'the-shorthand-editor' ),
				$retry_link
			);
		}

		if ( 426 === $status_code ) {
			wp_die(
				esc_html__( 'This version of the Shorthand plugin is no longer compatible with Shorthand. Please update the plugin to continue.', 'the-shorthand-editor' ),
				esc_html__( 'Plugin Update Required', 'the-shorthand-editor' ),
				array(
					'link_url'  => esc_url( self_admin_url( 'plugins.php' ) ),
					'link_text' => esc_html__( 'Go to Plugins', 'the-shorthand-editor' ),
				)
			);
		}

		if ( $status_code >= 500 && $status_code < 600 ) {
			wp_die(
				esc_html__( 'The Shorthand server encountered an error. Please try again in a few minutes. If this problem persists, contact Shorthand support.', 'the-shorthand-editor' ),
				esc_html__( 'Connection Failed', 'the-shorthand-editor' ),
				array_merge(
					$retry_link,
					array(
						'additional_errors' => array( $status_detail ),
					)
				)
			);
		}

		wp_die(
			esc_html__( 'An unexpected error occurred while connecting to Shorthand. Please try again or contact Shorthand support.', 'the-shorthand-editor' ),
			esc_html__( 'Connection Failed', 'the-shorthand-editor' ),
			array_merge(
				$retry_link,
				array(
					'additional_errors' => array( $status_detail ),
				)
			)
		);
	}

	public function get_story_creation_url( string $return_url ): string {
		return $this->get_authorised_resource_url( $return_url, 'stories', 'create' );
	}

	public function get_story_editor_url( string $return_url, string $story_id ): string {
		return $this->get_authorised_resource_url( $return_url, "stories/{$story_id}", 'edit' );
	}

	private function get_authorised_resource_url( string $return_url, string $resource, string $view_mode ): string {
		$base_url = $this->options->get_app_url();
		$team_id  = $this->options->get_token_team_id();
		$token    = $this->sign_identity_for_current_user( $resource, $return_url );
		return add_query_arg(
			array(
				'token'      => rawurlencode( $token ),
				'view_mode'  => rawurlencode( $view_mode ),
				'team'       => rawurlencode( $team_id ),
				'return_url' => rawurlencode( $return_url ),
			),
			"{$base_url}/integration/v2/authorise"
		);
	}

	/**
	 * @return \WP_Error|string
	 */
	public function get_story_title( string $story_id ) {
		$url = $this->options->get_api_url() . '/v2/stories/' . $story_id . '/settings';

		$response = $this->shorthand_api_authed_request(
			$url,
			'GET',
			array()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$error = new WP_Error( 'story', "The Shorthand story ID is {$story_id}.", $story_id );
			$error->add( 'pretty', 'The story title could not be retrieved.' );
			$error->add( 'status', "Retrieved HTTP status {$status}.", $status );
			return $error;
		}

		$body     = wp_remote_retrieve_body( $response );
		$settings = json_decode( $body, true );
		return isset( $settings['meta']['title'] ) ? $settings['meta']['title'] : 'Untitled story';
	}

	public function set_story_title( string $story_id, string $title ): ?\WP_Error {
		$url = $this->options->get_api_url() . '/v2/stories/' . $story_id . '/settings';

		$response = $this->shorthand_api_authed_request(
			$url,
			'POST',
			array(),
			array( 'meta' => array( 'title' => $title ) )
		);

		return is_wp_error( $response ) ? $response : null;
	}

	/**
	 * @return \WP_Error|bool
	 */
	public function set_story_external_id( string $story_id, int $post_id ) {
		$url = $this->options->get_api_url() . '/v2/stories/' . $story_id . '/settings';

		$response = $this->shorthand_api_authed_request(
			$url,
			'POST',
			array(),
			array(
				'external' => array(
					'externalId' => (string) $post_id,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code && 204 !== $status_code ) {
			return new WP_Error( 'status', "Received HTTP status code {$status_code}.", $status_code );
		}

		return true;
	}

	/**
	 * @return mixed[]|\WP_Error
	 */
	public function get_story_settings( $story_id ) {
		$url = $this->options->get_api_url() . '/v2/stories/' . $story_id . '/settings';

		$response = $this->shorthand_api_authed_request(
			$url,
			'GET',
			array(
				'timeout' => '10',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		$info     = 200 === $status_code ? json_decode( $body, true ) : null;
		$json_err = 200 === $status_code ? json_last_error() : JSON_ERROR_NONE;

		if ( ! $info || 200 !== $status_code || JSON_ERROR_NONE !== $json_err ) {
			$error = new WP_Error( 'story', "The Shorthand story ID is {$story_id}.", $story_id );
			if ( JSON_ERROR_NONE !== $json_err ) {
				$msg = json_last_error_msg();
				$error->add( 'json', "JSON decoding error message: {$msg}.", $json_err );
			} else {
				$error->add( 'status', "Received HTTP status {$status_code}.", $status_code );
			}
			return $error;
		}

		return $info;
	}

	/**
	 * @return mixed[]|\WP_Error
	 */
	public function shorthand_api_authed_request( $url, $method = 'GET', $options = array(), $body = null ) {
		return $this->api_client->authed_request( $url, $method, $options, $body );
	}

	/**
	 * Requests info from Shorthand about the given token.
	 * Returns the token info or WP_Error on failure.
	 *
	 * @param string $token
	 * @return mixed[]|\WP_Error Token info object or error
	 */
	public function fetch_token_info( $token ) {
		return $this->api_client->fetch_token_info( $token );
	}

	private function sign_identity_for_current_user( string $res, string $return_url ): string {
		try {
			$jwk_secret = $this->options->get_v2_signing_key();
			$alg        = $jwk_secret['alg'];
			$key        = $jwk_secret['d'];
		} catch ( Exception $e ) {
			wp_die(
				esc_html__( 'WordPress is no longer connected to a Shorthand workspace. Please contact your administrator.', 'the-shorthand-editor' ),
				'',
				array( 'back_link' => true )
			);
		}

		$user    = wp_get_current_user();
		$time    = time();
		$payload = array(
			'iss'             => get_site_url(),
			'aud'             => 'shorthand.com',
			'iat'             => $time,
			'exp'             => $time + 5 * 60, // 5 minutes
			'sub'             => "wordpress/{$user->ID}",
			'scope'           => 'stories',
			'session_request' => array(
				'return_url'        => $return_url,
				'resource_context'  => array(
					'resource'     => $res,
					'team'         => $this->options->get_token_team_id(),
					'organisation' => $this->options->get_token_org_id(),
				),
				'wordpress_context' => $this->context_provider->get_context(),
			),
		);

		return JWT::encode( $payload, $key, $alg );
	}

	private function sign_identity_for_connection( string $return_url, ?string $nonce = null ): string {
		[$signing_key, $verifying_key] = $this->options->get_v2_next_signing_and_verifying_keys();

		try {
			$jwk_secret = $signing_key;
			$jwk        = $verifying_key;
			$alg        = $jwk_secret['alg'];
			$key        = $jwk_secret['d'];
		} catch ( Exception $e ) {
			wp_die(
				esc_html__( 'WordPress is no longer connected to a Shorthand workspace. Please contact your administrator.', 'the-shorthand-editor' ),
				'',
				array( 'back_link' => true )
			);
		}

		$user    = wp_get_current_user();
		$time    = time();
		$payload = array(
			'iss'             => get_site_url(),
			'aud'             => 'shorthand.com',
			'iat'             => $time,
			'exp'             => $time + 15 * 60, // 15 minutes
			'sub'             => "wordpress/{$user->ID}",
			'scope'           => 'connect',
			'connect_request' => array(
				'return_url'        => $return_url,
				'wordpress_context' => $this->context_provider->get_context(),
			),
		);

		if ( $nonce ) {
			$payload['nonce'] = $nonce;
			$payload['jti']   = JWT::urlsafeB64Encode( random_bytes( 16 ) );
		}

		$head = array(
			'jwk' => $jwk,
		);

		return JWT::encode( $payload, $key, $alg, null, $head );
	}

	private function sign_dpop_for_connection( string $nonce ): string {
		[$signing_key, $verifying_key] = $this->options->get_v2_next_signing_and_verifying_keys();
		try {
			$jwk_secret = $signing_key;
			$jwk        = $verifying_key;
			$alg        = $jwk_secret['alg'];
			$key        = $jwk_secret['d'];
		} catch ( Exception $e ) {
			wp_die(
				esc_html__( 'WordPress is no longer connected to a Shorthand workspace. Please contact your administrator.', 'the-shorthand-editor' ),
				'',
				array( 'back_link' => true )
			);
		}

		$user    = wp_get_current_user();
		$time    = time();
		$payload = array(
			'iss'   => get_site_url(),
			'aud'   => 'shorthand.com',
			'iat'   => $time,
			'exp'   => $time + 15 * 60, // 15 minutes
			'sub'   => "wordpress/{$user->ID}",
			'scope' => 'connect',
		);

		$payload['nonce'] = $nonce;
		$payload['jti']   = JWT::urlsafeB64Encode( random_bytes( 16 ) );

		$head = array(
			'typ' => 'dpop+jwt',
			'jwk' => $jwk,
		);

		return JWT::encode( $payload, $key, $alg, null, $head );
	}

}
