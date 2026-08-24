<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\ConnectionErrorPage;
use Shorthand\Services\ConnectionFailure;
use Shorthand\Tests\WordPressTestCase;

final class ConnectionErrorPageTest extends WordPressTestCase {

	public function test_the_page_states_what_happened_why_and_what_to_do(): void {
		$html = ( new ConnectionErrorPage() )->build_html( ConnectionFailure::expired() );

		$this->assertStringContainsString( 'This connection attempt has expired', $html );
		$this->assertStringContainsString( 'no longer valid', $html );
		$this->assertStringContainsString( 'same browser session', $html );
		$this->assertStringContainsString( 'Try connecting again', $html );
	}

	public function test_the_primary_action_links_to_the_connect_start_action(): void {
		$html = ( new ConnectionErrorPage() )->build_html( ConnectionFailure::expired() );

		$this->assertStringContainsString( 'sh-action-primary', $html );
		$this->assertStringContainsString( 'admin-post.php?action=shorthand_connect_start', $html );
	}

	public function test_the_failure_slug_is_shown_as_an_error_reference(): void {
		$html = ( new ConnectionErrorPage() )->build_html( ConnectionFailure::blocked_by_firewall() );

		$this->assertStringContainsString( 'connect.rejected.edge', $html );
	}

	public function test_diagnostics_go_to_the_console_not_the_page_body(): void {
		$failure = ConnectionFailure::server_error()->with_diagnostics(
			array( 'http_status' => 500, 'edge_id' => 'cf-request-id==' )
		);

		$html = ( new ConnectionErrorPage() )->build_html( $failure );

		$script_start = strpos( $html, '<script>' );
		$this->assertNotFalse( $script_start );
		$body_without_script = substr( $html, 0, $script_start );

		$this->assertStringNotContainsString( 'cf-request-id', $body_without_script );
		$this->assertStringContainsString( 'console.info', $html );
		$this->assertStringContainsString( '"edge_id":"cf-request-id=="', $html );
		$this->assertStringContainsString( '"mode":"connect.server-error"', $html );
		$this->assertStringContainsString( '"plugin_version"', $html );
	}

	public function test_diagnostics_carry_the_served_status_even_without_classifier_data(): void {
		$html = ( new ConnectionErrorPage() )->build_html( ConnectionFailure::expired() );

		$this->assertStringContainsString( '"page_status":400', $html );
	}

	public function test_the_two_inactive_connection_pages_have_distinct_modes(): void {
		$this->assertNotSame(
			ConnectionFailure::connection_inactive_admin()->get_slug(),
			ConnectionFailure::connection_inactive()->get_slug()
		);
	}

	public function test_rendering_sends_the_failure_status_and_no_cache_headers(): void {
		ob_start();
		( new ConnectionErrorPage() )->render( ConnectionFailure::expired(), false );
		$output = ob_get_clean();

		$this->assertSame( array( 400 ), \tests_wp_status_headers() );
		$this->assertSame( 1, \tests_wp_nocache_calls() );
		$this->assertStringContainsString( '<!DOCTYPE html>', $output );
	}

	public function test_a_cancelled_connection_renders_as_a_200(): void {
		ob_start();
		( new ConnectionErrorPage() )->render( ConnectionFailure::canceled(), false );
		ob_end_clean();

		$this->assertSame( array( 200 ), \tests_wp_status_headers() );
	}

	public function test_a_diagnostic_value_cannot_break_out_of_the_script_block(): void {
		$failure = ConnectionFailure::server_error()->with_diagnostics(
			array( 'server' => '</script><script>alert(1)</script>' )
		);

		$html = ( new ConnectionErrorPage() )->build_html( $failure );

		$this->assertStringNotContainsString( '</script><script>alert(1)', $html );
		$this->assertStringContainsString( '\u003C/script\u003E\u003Cscript\u003Ealert(1)', $html );
	}

	public function test_the_page_never_contains_a_token_like_diagnostic(): void {
		$failure = ConnectionFailure::rejected_by_api()->with_diagnostics(
			array( 'request_url' => 'https://api.shorthand.com/v2/connect?type=wordpress' )
		);

		$html = ( new ConnectionErrorPage() )->build_html( $failure );

		$this->assertStringNotContainsString( 'token=', $html );
		$this->assertStringNotContainsString( 'apiToken', $html );
	}
}
