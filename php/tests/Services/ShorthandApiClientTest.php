<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
use Shorthand\Services\Options;
use Shorthand\Services\ShorthandApiClient;
use Shorthand\Services\ShorthandHttpTransport;
use Shorthand\Tests\WordPressTestCase;

final class ShorthandApiClientTest extends WordPressTestCase {

	public function test_authed_request_requires_a_linked_workspace_token(): void {
		$options   = $this->createMock( Options::class );
		$version   = $this->createMock( Version::class );
		$transport = $this->createMock( ShorthandHttpTransport::class );

		$options
			->expects( $this->once() )
			->method( 'get_v2_token' )
			->willReturn( '' );

		$client = new ShorthandApiClient( $options, $version, $transport );

		$result = $client->authed_request( 'https://example.test/api' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'settings', $result->get_error_code() );
	}

	public function test_authed_request_adds_auth_headers_and_json_body(): void {
		$GLOBALS['wp_version'] = '6.8.0';

		$options   = $this->createMock( Options::class );
		$version   = $this->createMock( Version::class );
		$transport = $this->createMock( ShorthandHttpTransport::class );

		$options
			->expects( $this->once() )
			->method( 'get_v2_token' )
			->willReturn( 'api-token' );

		$version
			->expects( $this->once() )
			->method( 'get_plugin_name' )
			->willReturn( 'The Shorthand Editor' );

		$version
			->expects( $this->once() )
			->method( 'get_plugin_version' )
			->willReturn( '1.0.2' );

		$transport
			->expects( $this->once() )
			->method( 'request' )
			->with(
				'https://example.test/api',
				$this->callback(
					function ( array $request_options ): bool {
						return $request_options['method'] === 'POST'
							&& $request_options['sslverify'] === 1
							&& $request_options['headers']['Authorization'] === 'Token api-token'
							&& $request_options['headers']['Content-Type'] === 'application/json'
							&& $request_options['headers']['user-agent'] === 'WordPress/6.8.0 The Shorthand Editor/1.0.2'
							&& $request_options['body'] === '{"story":"abc"}';
					}
				)
			)
			->willReturn(
				array(
					'response' => array(
						'code' => 200,
					),
					'body'     => '{}',
				)
			);

		$client = new ShorthandApiClient( $options, $version, $transport );

		$result = $client->authed_request(
			'https://example.test/api',
			'POST',
			array(),
			array( 'story' => 'abc' )
		);

		$this->assertSame( 200, $result['response']['code'] );
	}

	public function test_fetch_token_info_decodes_the_successful_response_body(): void {
		$options   = $this->createMock( Options::class );
		$version   = $this->createMock( Version::class );
		$transport = $this->createMock( ShorthandHttpTransport::class );

		$options
			->expects( $this->once() )
			->method( 'get_api_url' )
			->willReturn( 'https://api.example.test' );

		$version
			->expects( $this->once() )
			->method( 'get_plugin_name' )
			->willReturn( 'The Shorthand Editor' );

		$version
			->expects( $this->once() )
			->method( 'get_plugin_version' )
			->willReturn( '1.0.2' );

		$transport
			->expects( $this->once() )
			->method( 'request' )
			->with(
				'https://api.example.test/v2/token-info',
				$this->callback(
					function ( array $request_options ): bool {
						return $request_options['headers']['Authorization'] === 'Token token-123';
					}
				)
			)
			->willReturn(
				array(
					'response' => array(
						'code' => 200,
					),
					'body'     => '{"team":"newsroom"}',
				)
			);

		$client = new ShorthandApiClient( $options, $version, $transport );

		$this->assertSame(
			array( 'team' => 'newsroom' ),
			$client->fetch_token_info( 'token-123' )
		);
	}
}
