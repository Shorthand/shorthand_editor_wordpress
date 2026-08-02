<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\ConnectionFailureClassifier;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

final class ConnectionFailureClassifierTest extends WordPressTestCase {

	/**
	 * @var ConnectionFailureClassifier
	 */
	private $classifier;

	protected function setUp(): void {
		parent::setUp();
		$this->classifier = new ConnectionFailureClassifier();
	}

	/**
	 * @param array<string, mixed> $headers
	 * @return array<string, mixed>
	 */
	private function response( int $code, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	public function test_a_usable_success_response_is_not_a_failure(): void {
		$response = $this->response( 200, '{"apiToken":"abc123"}' );

		$this->assertNull( $this->classifier->classify( $response ) );
	}

	public function test_dns_errors_classify_as_transport_dns(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.shorthand.com' );

		$failure = $this->classifier->classify( $error );

		$this->assertSame( 'connect.transport.dns', $failure->get_slug() );
		$this->assertSame( 502, $failure->get_status() );
	}

	public function test_certificate_errors_classify_as_transport_tls(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' );

		$failure = $this->classifier->classify( $error );

		$this->assertSame( 'connect.transport.tls', $failure->get_slug() );
	}

	public function test_timeouts_classify_as_transport_timeout(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' );

		$failure = $this->classifier->classify( $error );

		$this->assertSame( 'connect.transport.timeout', $failure->get_slug() );
	}

	public function test_refused_connections_classify_as_transport_unreachable(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to api.shorthand.com port 443: Connection refused' );

		$failure = $this->classifier->classify( $error );

		$this->assertSame( 'connect.transport.unreachable', $failure->get_slug() );
	}

	public function test_transport_diagnostics_carry_the_wp_error_details(): void {
		$error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' );

		$diagnostics = $this->classifier->classify( $error )->get_diagnostics();

		$this->assertSame( 'http_request_failed', $diagnostics['wp_error_code'] );
		$this->assertStringContainsString( 'timed out', $diagnostics['wp_error_message'] );
	}

	public function test_a_json_403_is_an_api_rejection(): void {
		$response = $this->response( 403, '{"code":"FORBIDDEN","message":"nope"}' );

		$failure = $this->classifier->classify( $response );

		$this->assertSame( 'connect.rejected.api', $failure->get_slug() );
	}

	public function test_an_html_403_is_a_firewall_block_not_an_api_rejection(): void {
		$body     = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01"><HTML><H1>403 ERROR</H1>Request blocked.</HTML>';
		$response = $this->response( 403, $body, array( 'x-cache' => 'Error from cloudfront', 'x-amz-cf-id' => 'abc==' ) );

		$failure = $this->classifier->classify( $response );

		$this->assertSame( 'connect.rejected.edge', $failure->get_slug() );
		$this->assertSame( 'Error from cloudfront', $failure->get_diagnostics()['edge_cache'] );
		$this->assertSame( 'html', $failure->get_diagnostics()['body_format'] );
	}

	public function test_a_426_requires_a_plugin_upgrade(): void {
		$failure = $this->classifier->classify( $this->response( 426, '{}' ) );

		$this->assertSame( 'connect.upgrade-required', $failure->get_slug() );
	}

	public function test_a_429_is_rate_limited(): void {
		$failure = $this->classifier->classify( $this->response( 429, '{"error":"too many requests"}' ) );

		$this->assertSame( 'connect.rate-limited', $failure->get_slug() );
		$this->assertSame( 503, $failure->get_status() );
	}

	public function test_a_5xx_is_a_server_error_with_the_status_in_diagnostics(): void {
		$failure = $this->classifier->classify( $this->response( 500, '{"code":"INTERNAL_SERVER_ERROR"}' ) );

		$this->assertSame( 'connect.server-error', $failure->get_slug() );
		$this->assertSame( 500, $failure->get_diagnostics()['http_status'] );
	}

	public function test_an_unexpected_status_is_classified_as_such(): void {
		$failure = $this->classifier->classify( $this->response( 404, 'not found' ) );

		$this->assertSame( 'connect.unexpected-response', $failure->get_slug() );
	}

	public function test_a_success_without_an_api_token_is_invalid(): void {
		$failure = $this->classifier->classify( $this->response( 200, '{"unexpected":"shape"}' ) );

		$this->assertSame( 'connect.response-invalid', $failure->get_slug() );
	}

	public function test_a_success_with_an_unparseable_body_is_invalid(): void {
		$failure = $this->classifier->classify( $this->response( 200, '<html>cached page</html>' ) );

		$this->assertSame( 'connect.response-invalid', $failure->get_slug() );
		$this->assertSame( 'html', $failure->get_diagnostics()['body_format'] );
	}
}
