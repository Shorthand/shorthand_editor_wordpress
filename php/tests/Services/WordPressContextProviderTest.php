<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
use Shorthand\Services\WordPressContextProvider;
use Shorthand\Tests\WordPressTestCase;

final class WordPressContextProviderTest extends WordPressTestCase {

	public function test_it_builds_wordpress_context_from_version_and_site_state(): void {
		$GLOBALS['wp_version'] = '6.8.0';

		$version = $this->createMock( Version::class );

		$version
			->expects( $this->once() )
			->method( 'get_plugin_name' )
			->willReturn( 'The Shorthand Editor' );

		$version
			->expects( $this->once() )
			->method( 'get_plugin_version' )
			->willReturn( '1.0.2' );

		$this->assertSame(
			array(
				'wp_version'     => '6.8.0',
				'plugin_name'    => 'The Shorthand Editor',
				'plugin_version' => '1.0.2',
				'site_name'      => 'Example Site',
				'site_url'       => 'https://example.test',
				'site_rest_url'  => 'https://example.test/wp-json',
			),
			( new WordPressContextProvider( $version ) )->get_context()
		);
	}

	/**
	 * WordPress escapes `blogname` on save; the context travels as JSON.
	 *
	 * @see https://linear.app/shorthand/issue/PLA-2464
	 */
	public function test_it_sends_the_site_name_as_plain_text(): void {
		$GLOBALS['wp_version']                          = '6.8.0';
		$GLOBALS['tests_wp_state']['bloginfo']['name'] = 'Don&#039;t Call Me';

		$version = $this->createMock( Version::class );
		$version->method( 'get_plugin_name' )->willReturn( 'The Shorthand Editor' );
		$version->method( 'get_plugin_version' )->willReturn( '1.0.2' );

		$context = ( new WordPressContextProvider( $version ) )->get_context();

		$this->assertSame( "Don't Call Me", $context['site_name'] );
	}
}
