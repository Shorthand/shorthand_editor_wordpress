<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Admin\ConnectionErrorPage;
use Shorthand\Core\Version;
use Shorthand\Services\ConnectionFailure;
use Shorthand\Services\ConnectionFailureClassifier;
use Shorthand\Services\Options;
use Shorthand\Services\Shorthand;
use Shorthand\Services\ShorthandApiClient;
use Shorthand\Services\WordPressContextProvider;
use Shorthand\Tests\WordPressTestCase;
use Shorthand\Vendor\Firebase\JWT\JWT;

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

	/**
	 * Wire a service for connect() tests: mocked transport and error page,
	 * valid signing keys, real classifier unless one is supplied.
	 *
	 * @param ?ConnectionFailureClassifier $classifier Replaces the real classifier.
	 * @return array{Shorthand, \PHPUnit\Framework\MockObject\MockObject&ShorthandApiClient, \PHPUnit\Framework\MockObject\MockObject&ConnectionErrorPage, \PHPUnit\Framework\MockObject\MockObject&Options}
	 */
	private function connectable_service( ?ConnectionFailureClassifier $classifier = null ): array {
		$options          = $this->createMock( Options::class );
		$api_client       = $this->createMock( ShorthandApiClient::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );
		$error_page       = $this->createMock( ConnectionErrorPage::class );

		$key_pair = sodium_crypto_sign_keypair();
		$options->method( 'get_api_url' )->willReturn( 'https://api.example.test' );
		$options->method( 'get_v2_next_signing_and_verifying_keys' )->willReturn(
			array(
				array(
					'kty' => 'OKP',
					'crv' => 'Ed25519',
					'alg' => 'EdDSA',
					'd'   => base64_encode( sodium_crypto_sign_secretkey( $key_pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test key material, matches the production key format.
				),
				array(
					'kty' => 'OKP',
					'kid' => 'test-key',
					'crv' => 'Ed25519',
					'x'   => JWT::urlsafeB64Encode( sodium_crypto_sign_publickey( $key_pair ) ),
				),
			)
		);
		$context_provider->method( 'get_context' )->willReturn( array() );

		$service = new Shorthand(
			$options,
			$this->createMock( Version::class ),
			$api_client,
			$context_provider,
			$error_page,
			$classifier ?? new ConnectionFailureClassifier()
		);

		return array( $service, $api_client, $error_page, $options );
	}

	/**
	 * A return token whose middle segment decodes to the given claims.
	 *
	 * @param array<string, mixed> $claims JWT body claims.
	 */
	private function return_token( array $claims ): string {
		return 'head.' . JWT::urlsafeB64Encode( (string) wp_json_encode( $claims ) ) . '.sig';
	}

	/**
	 * Expect one render() call whose failure carries the given slug.
	 *
	 * @param \PHPUnit\Framework\MockObject\MockObject&ConnectionErrorPage $error_page        The error page mock.
	 * @param string                                                       $slug              Expected failure slug.
	 * @param ?callable                                                    $diagnostics_check Extra check on the failure diagnostics.
	 */
	private function expect_rendered_slug( $error_page, string $slug, ?callable $diagnostics_check = null ): void {
		$error_page
			->expects( $this->once() )
			->method( 'render' )
			->with(
				$this->callback(
					function ( ConnectionFailure $failure ) use ( $slug, $diagnostics_check ): bool {
						return $failure->get_slug() === $slug
							&& ( null === $diagnostics_check || $diagnostics_check( $failure->get_diagnostics() ) );
					}
				)
			);
	}

	public function test_connect_renders_the_malformed_token_page_for_a_non_jwt(): void {
		[$service, $api_client, $error_page] = $this->connectable_service();

		$api_client->expects( $this->never() )->method( 'request' );
		$this->expect_rendered_slug(
			$error_page,
			'connect.token.malformed',
			function ( array $diagnostics ): bool {
				return \Exception::class === $diagnostics['parse_error'];
			}
		);

		$service->connect( 'not-a-jwt' );
	}

	public function test_connect_renders_the_malformed_token_page_when_the_nonce_is_not_a_string(): void {
		[$service, $api_client, $error_page] = $this->connectable_service();

		$api_client->expects( $this->never() )->method( 'request' );
		$this->expect_rendered_slug( $error_page, 'connect.token.malformed' );

		$service->connect( $this->return_token( array( 'nonce' => array( 'unexpected' ) ) ) );
	}

	public function test_connect_renders_the_malformed_token_page_when_the_nonce_is_missing(): void {
		[$service, $api_client, $error_page] = $this->connectable_service();

		$api_client->expects( $this->never() )->method( 'request' );
		$this->expect_rendered_slug(
			$error_page,
			'connect.token.malformed',
			function ( array $diagnostics ): bool {
				return 'missing-nonce' === $diagnostics['parse_error'];
			}
		);

		$service->connect( $this->return_token( array( 'sub' => 'no-nonce' ) ) );
	}

	public function test_connect_renders_the_classified_failure_with_the_request_url(): void {
		[$service, $api_client, $error_page] = $this->connectable_service();

		$api_client->method( 'request' )->willReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"code":"INTERNAL_SERVER_ERROR"}',
			)
		);
		$this->expect_rendered_slug(
			$error_page,
			'connect.server-error',
			function ( array $diagnostics ): bool {
				return false !== strpos( $diagnostics['request_url'], '/v2/connect' );
			}
		);

		$service->connect( $this->return_token( array( 'nonce' => 'n1' ) ) );

		$this->assertFalse( get_option( 'shorthand_v2_token' ) );
	}

	public function test_connect_stores_the_api_token_on_success(): void {
		[$service, $api_client, $error_page, $options] = $this->connectable_service();

		$api_client->method( 'request' )->willReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"apiToken":"fresh-token"}',
			)
		);
		$error_page->expects( $this->never() )->method( 'render' );
		$options->expects( $this->once() )->method( 'update_v2_signing_keys' );

		$service->connect( $this->return_token( array( 'nonce' => 'n1' ) ) );

		$this->assertSame( 'fresh-token', get_option( 'shorthand_v2_token' ) );
	}

	public function test_connect_rejects_a_success_the_classifier_passed_without_a_token(): void {
		$permissive_classifier = $this->createMock( ConnectionFailureClassifier::class );
		$permissive_classifier->method( 'classify' )->willReturn( null );

		[$service, $api_client, $error_page] = $this->connectable_service( $permissive_classifier );

		$api_client->method( 'request' )->willReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"unexpected":"shape"}',
			)
		);
		$this->expect_rendered_slug( $error_page, 'connect.response-invalid' );

		$service->connect( $this->return_token( array( 'nonce' => 'n1' ) ) );

		$this->assertFalse( get_option( 'shorthand_v2_token' ) );
	}

	public function test_missing_next_keys_render_the_keys_missing_page(): void {
		$options          = $this->createMock( Options::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );
		$error_page       = $this->createMock( ConnectionErrorPage::class );

		// The stored-option shape when the key options are absent.
		$options->method( 'get_v2_next_signing_and_verifying_keys' )->willReturn( array( null, null ) );
		$context_provider->method( 'get_context' )->willReturn( array() );

		$this->expect_rendered_slug(
			$error_page,
			'connect.keys-missing',
			function ( array $diagnostics ): bool {
				return isset( $diagnostics['exception'] );
			}
		);

		$service = new Shorthand(
			$options,
			$this->createMock( Version::class ),
			$this->createMock( ShorthandApiClient::class ),
			$context_provider,
			$error_page,
			new ConnectionFailureClassifier()
		);

		// The null keys emit array-offset warnings before the guarded
		// TypeError; keep them out of the suite's warning report.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only warning suppression.
			static function (): bool {
				return true;
			},
			E_WARNING
		);
		try {
			$service->get_integration_url( 'https://blog.example.test/return' );
		} finally {
			restore_error_handler();
		}
	}

	public function test_a_corrupt_signing_key_renders_the_keys_missing_page(): void {
		$options          = $this->createMock( Options::class );
		$context_provider = $this->createMock( WordPressContextProvider::class );
		$error_page       = $this->createMock( ConnectionErrorPage::class );

		// Options::get_v2_signing_key(): array throws when the option is absent.
		$options->method( 'get_v2_signing_key' )->willThrowException(
			new \TypeError( 'Return value must be of type array, null returned' )
		);

		$this->expect_rendered_slug(
			$error_page,
			'connect.keys-missing',
			function ( array $diagnostics ): bool {
				return \TypeError::class === ( $diagnostics['exception'] ?? null );
			}
		);

		$service = new Shorthand(
			$options,
			$this->createMock( Version::class ),
			$this->createMock( ShorthandApiClient::class ),
			$context_provider,
			$error_page,
			new ConnectionFailureClassifier()
		);

		$service->get_story_creation_url( 'https://blog.example.test/return' );
	}
}
