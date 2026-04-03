<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin\Actions;

use Shorthand\Admin\Actions\ConnectionCompletionService;
use Shorthand\Admin\AdminGateway;
use Shorthand\Services\Shorthand;
use Shorthand\Tests\WordPressTestCase;

final class ConnectionCompletionServiceTest extends WordPressTestCase {

	public function test_redirects_to_the_post_editor_when_a_post_id_is_available(): void {
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );

		$shorthand
			->expects( $this->once() )
			->method( 'connect' )
			->with( 'token-123' );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_edit_post_link' )
			->with( 7 )
			->willReturn( 'https://example.test/post/7/edit' );

		$service = new ConnectionCompletionService( $shorthand, $admin_gateway );

		$this->assertSame(
			'https://example.test/post/7/edit',
			$service->complete( 'token-123', 7 )
		);
	}

	public function test_redirects_to_settings_when_there_is_no_post_to_return_to(): void {
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );

		$shorthand
			->expects( $this->once() )
			->method( 'connect' )
			->with( 'token-123' );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_settings_page_url' )
			->willReturn( 'https://example.test/settings' );

		$service = new ConnectionCompletionService( $shorthand, $admin_gateway );

		$this->assertSame(
			'https://example.test/settings',
			$service->complete( 'token-123', 0 )
		);
	}
}
