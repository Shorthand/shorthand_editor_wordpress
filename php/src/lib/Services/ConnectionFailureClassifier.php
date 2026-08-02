<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Classifies the outcome of the connect handshake into a failure mode.
 *
 * The connect POST can fail in many distinct ways that historically
 * collapsed into two generic messages. This classifier inspects the
 * transport error or HTTP response and picks the ConnectionFailure whose
 * copy and remediation actually match the cause, attaching support-only
 * diagnostics (never the token) for the browser console.
 */
class ConnectionFailureClassifier {

	/**
	 * Classify a connect-handshake result.
	 *
	 * @param array|\WP_Error $response Result of the connect POST.
	 * @return ConnectionFailure|null Null when the response is a usable success.
	 */
	public function classify( $response ) {
		if ( is_wp_error( $response ) ) {
			return $this->classify_transport_error( $response );
		}

		return $this->classify_response( $response );
	}

	/**
	 * Pick the transport failure mode that matches a WP_Error.
	 *
	 * @param \WP_Error $error Transport-level error from the HTTP API.
	 */
	private function classify_transport_error( WP_Error $error ): ConnectionFailure {
		$message = strtolower( $error->get_error_message() );

		$failure = ConnectionFailure::transport_unreachable();
		if ( $this->message_contains( $message, array( 'could not resolve', 'couldn\'t resolve', 'name or service not known', 'getaddrinfo', 'name lookup' ) ) ) {
			$failure = ConnectionFailure::transport_dns();
		} elseif ( $this->message_contains( $message, array( 'ssl', 'certificate', 'tls' ) ) ) {
			$failure = ConnectionFailure::transport_tls();
		} elseif ( $this->message_contains( $message, array( 'timed out', 'timeout', 'operation was aborted' ) ) ) {
			$failure = ConnectionFailure::transport_timeout();
		}

		return $failure->with_diagnostics(
			array(
				'wp_error_code'    => $error->get_error_code(),
				'wp_error_message' => $error->get_error_message(),
			)
		);
	}

	/**
	 * Pick the failure mode that matches an HTTP response, if any.
	 *
	 * @param array $response HTTP response array from the HTTP API.
	 */
	private function classify_response( array $response ): ?ConnectionFailure {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$is_json = null !== $decoded || 'null' === trim( $body );

		$diagnostics = array_filter(
			array(
				'http_status' => $status,
				'body_format' => $is_json ? 'json' : $this->body_format( $body ),
				'edge_cache'  => (string) wp_remote_retrieve_header( $response, 'x-cache' ),
				'edge_id'     => (string) wp_remote_retrieve_header( $response, 'x-amz-cf-id' ),
				'server'      => (string) wp_remote_retrieve_header( $response, 'server' ),
			),
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		if ( 200 === $status ) {
			$token = is_array( $decoded ) && isset( $decoded['apiToken'] ) ? $decoded['apiToken'] : null;
			if ( is_string( $token ) && '' !== $token ) {
				return null;
			}
			return ConnectionFailure::response_invalid()->with_diagnostics( $diagnostics );
		}

		if ( 401 === $status || 403 === $status ) {
			/*
			 * The Shorthand API answers JSON. An HTML (or otherwise
			 * non-JSON) 403 means an edge — a WAF, CDN, or security
			 * proxy — blocked the request before it reached Shorthand,
			 * which needs entirely different remediation.
			 */
			if ( ! $is_json ) {
				return ConnectionFailure::blocked_by_firewall()->with_diagnostics( $diagnostics );
			}
			return ConnectionFailure::rejected_by_api()->with_diagnostics( $diagnostics );
		}

		if ( 426 === $status ) {
			return ConnectionFailure::upgrade_required()->with_diagnostics( $diagnostics );
		}

		if ( 429 === $status ) {
			return ConnectionFailure::rate_limited()->with_diagnostics( $diagnostics );
		}

		if ( $status >= 500 && $status < 600 ) {
			return ConnectionFailure::server_error()->with_diagnostics( $diagnostics );
		}

		return ConnectionFailure::unexpected_response()->with_diagnostics( $diagnostics );
	}

	/**
	 * Whether the message contains any of the given fragments.
	 *
	 * @param string $message Lower-cased error message.
	 * @param array  $needles Fragments to look for.
	 */
	private function message_contains( string $message, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Describe a non-JSON body for the diagnostics payload.
	 *
	 * @param string $body Raw response body.
	 */
	private function body_format( string $body ): string {
		if ( '' === trim( $body ) ) {
			return 'empty';
		}
		if ( false !== stripos( $body, '<html' ) || false !== stripos( $body, '<!doctype' ) ) {
			return 'html';
		}
		return 'text';
	}
}
