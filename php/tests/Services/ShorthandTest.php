<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Admin\ConnectionErrorPage;
use Shorthand\Core\Version;
use Shorthand\Services\ConnectionFailureClassifier;
use Shorthand\Services\Options;
use Shorthand\Services\Shorthand;
use Shorthand\Services\ShorthandApiClient;
use Shorthand\Services\WordPressContextProvider;
use Shorthand\Tests\WordPressTestCase;

final class ShorthandTest extends WordPressTestCase {

	public function test_shorthand_api_authed_request_delegates_to_the_api_client(): void {
		$options          = $this->createMock( Options::class );
		$version          = $this->createMock( Version::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );

		$api_client
			->expects( $this->once() )
			->method( 'authed_request' )
			->with(
				'https://example.test/api',
				'POST',
				array( 'timeout' => 5 ),
				array( 'story' => 'abc' )
			)
			->willReturn(
				array(
					'response' => array(
						'code' => 200,
					),
				)
			);

		$service = new Shorthand( $options, $version, $api_client, $context_provider, new ConnectionErrorPage(), new ConnectionFailureClassifier() );

		$this->assertSame(
			200,
			$service->shorthand_api_authed_request(
				'https://example.test/api',
				'POST',
				array( 'timeout' => 5 ),
				array( 'story' => 'abc' )
			)['response']['code']
		);
	}

	public function test_fetch_token_info_delegates_to_the_api_client(): void {
		$options          = $this->createMock( Options::class );
		$version          = $this->createMock( Version::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );

		$api_client
			->expects( $this->once() )
			->method( 'fetch_token_info' )
			->with( 'token-123' )
			->willReturn( array( 'team' => 'newsroom' ) );

		$service = new Shorthand( $options, $version, $api_client, $context_provider, new ConnectionErrorPage(), new ConnectionFailureClassifier() );

		$this->assertSame(
			array( 'team' => 'newsroom' ),
			$service->fetch_token_info( 'token-123' )
		);
	}

	public function test_list_stories_builds_request_with_cursor_limit_and_keyword(): void {
		$options          = $this->createMock( Options::class );
		$version          = $this->createMock( Version::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );

		$options->expects( $this->once() )->method( 'get_api_url' )->willReturn( 'https://api.example.test' );

		$api_client
			->expects( $this->once() )
			->method( 'authed_request' )
			->with(
				$this->callback(
					function ( string $url ): bool {
						return 0 === strpos( $url, 'https://api.example.test/v2/stories' )
							&& false !== strpos( $url, 'limit=20' )
							&& false !== strpos( $url, 'keyword=hello' )
							&& false !== strpos( $url, 'cursor=abc' );
					}
				),
				'GET',
				array( 'timeout' => '10' ),
				null
			)
			->willReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							array(
								'id'    => 's1',
								'title' => 'A story',
							),
						)
					),
				)
			);

		$service = new Shorthand( $options, $version, $api_client, $context_provider, new ConnectionErrorPage(), new ConnectionFailureClassifier() );

		$result = $service->list_stories(
			array(
				'cursor'  => 'abc',
				'limit'   => 20,
				'keyword' => 'hello',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 's1', $result[0]['id'] );
	}

	public function test_list_stories_with_no_args_omits_optional_params(): void {
		$options          = $this->createMock( Options::class );
		$version          = $this->createMock( Version::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );

		$options->method( 'get_api_url' )->willReturn( 'https://api.example.test' );

		$api_client
			->expects( $this->once() )
			->method( 'authed_request' )
			->with(
				'https://api.example.test/v2/stories',
				'GET',
				array( 'timeout' => '10' ),
				null
			)
			->willReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array() ),
				)
			);

		$service = new Shorthand( $options, $version, $api_client, $context_provider, new ConnectionErrorPage(), new ConnectionFailureClassifier() );

		$this->assertSame( array(), $service->list_stories() );
	}

	public function test_list_stories_returns_wp_error_on_non_200_status(): void {
		$options          = $this->createMock( Options::class );
		$version          = $this->createMock( Version::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );

		$options->method( 'get_api_url' )->willReturn( 'https://api.example.test' );

		$api_client->method( 'authed_request' )->willReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '',
			)
		);

		$service = new Shorthand( $options, $version, $api_client, $context_provider, new ConnectionErrorPage(), new ConnectionFailureClassifier() );

		$result = $service->list_stories();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_list_stories_returns_wp_error_when_the_api_client_fails(): void {
		$options          = $this->createMock( Options::class );
		$version          = $this->createMock( Version::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );

		$options->method( 'get_api_url' )->willReturn( 'https://api.example.test' );

		$api_client->method( 'authed_request' )->willReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$service = new Shorthand( $options, $version, $api_client, $context_provider, new ConnectionErrorPage(), new ConnectionFailureClassifier() );

		$result = $service->list_stories();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
