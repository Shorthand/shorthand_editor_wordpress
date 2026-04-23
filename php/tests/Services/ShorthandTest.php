<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
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

		$service = new Shorthand( $options, $version, $api_client, $context_provider );

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

		$service = new Shorthand( $options, $version, $api_client, $context_provider );

		$this->assertSame(
			array( 'team' => 'newsroom' ),
			$service->fetch_token_info( 'token-123' )
		);
	}
}
