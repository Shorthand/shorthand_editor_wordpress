<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin\Actions;

use Exception;
use Shorthand\Admin\AdminGateway;
use Shorthand\Admin\Actions\ConnectionCompletionService;
use Shorthand\Admin\Actions\ReturnToConnect;
use Shorthand\Admin\ConnectionErrorPage;
use Shorthand\Services\ConnectionFailure;
use Shorthand\Services\Shorthand;
use Shorthand\Tests\WordPressTestCase;

final class ReturnToConnectTest extends WordPressTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject&ConnectionCompletionService
	 */
	private $completion_service;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject&ConnectionErrorPage
	 */
	private $error_page;

	/**
	 * @var ReturnToConnect
	 */
	private $action;

	protected function setUp(): void {
		parent::setUp();
		$_GET = array();

		$this->completion_service = $this->createMock( ConnectionCompletionService::class );
		$this->error_page         = $this->createMock( ConnectionErrorPage::class );

		$this->action = new ReturnToConnect(
			$this->createMock( Shorthand::class ),
			$this->completion_service,
			$this->createMock( AdminGateway::class ),
			$this->error_page
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function expect_failure_slug( string $slug ): void {
		$this->error_page
			->expects( $this->once() )
			->method( 'render' )
			->with(
				$this->callback(
					function ( ConnectionFailure $failure ) use ( $slug ) {
						return $failure->get_slug() === $slug;
					}
				)
			);
	}

	public function test_a_non_admin_gets_the_permission_page(): void {
		\tests_wp_set_current_user_can( false );

		$this->expect_failure_slug( 'connect.permission-return' );

		$this->action->render_page();
	}

	public function test_a_missing_nonce_gets_the_expired_page(): void {
		$this->expect_failure_slug( 'connect.expired' );

		$this->action->render_page();
	}

	public function test_an_invalid_nonce_gets_the_expired_page(): void {
		$_GET['_wpnonce'] = 'stale';
		\tests_wp_set_verify_nonce( false );

		$this->expect_failure_slug( 'connect.expired' );

		$this->action->render_page();
	}

	public function test_a_tokenless_return_is_a_cancellation(): void {
		$_GET['_wpnonce'] = 'valid';

		$this->expect_failure_slug( 'connect.canceled' );

		$this->action->render_page();
	}

	public function test_an_exception_during_completion_gets_a_branded_page_not_a_fatal(): void {
		$_GET['_wpnonce'] = 'valid';
		$_GET['token']    = 'a.b.c';

		$this->completion_service
			->method( 'complete' )
			->willThrowException( new Exception( 'unexpected JWT library failure' ) );

		$this->error_page
			->expects( $this->once() )
			->method( 'render' )
			->with(
				$this->callback(
					function ( ConnectionFailure $failure ) {
						$diagnostics = $failure->get_diagnostics();
						return 'connect.unexpected-error' === $failure->get_slug()
							&& Exception::class === $diagnostics['exception'];
					}
				)
			);

		$this->action->render_page();
	}
}
