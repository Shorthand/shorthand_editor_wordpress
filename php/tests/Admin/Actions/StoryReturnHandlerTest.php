<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin\Actions;

use PHPUnit\Framework\MockObject\MockObject;
use Shorthand\Admin\Actions\ActionResult;
use Shorthand\Admin\Actions\StoryEditorLinkBuilder;
use Shorthand\Admin\Actions\StoryReturnHandler;
use Shorthand\Admin\AdminGateway;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Tests\WordPressTestCase;

final class StoryReturnHandlerTest extends WordPressTestCase {

	public function test_returns_error_result_for_navigation_error(): void {
		$post_api     = $this->createMock( PostAPI::class );
		$shorthand    = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );
		$link_builder = $this->createMock( StoryEditorLinkBuilder::class );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_edit_post_link' )
			->with( 7 )
			->willReturn( 'https://example.test/post/7' );

		$handler = new StoryReturnHandler( $post_api, $shorthand, $admin_gateway, $link_builder, 'tse_story' );

		$result = $handler->handle( 7, null, 'oops', null, null );

		$this->assertTrue( $result->isError() );
		$this->assertSame( 'https://example.test/post/7', $result->getLinkUrl() );
		$this->assertSame( 'Return to story', $result->getLinkText() );
	}

	public function test_rejects_unexpected_post_type_during_story_creation(): void {
		$admin_gateway = $this->createMock( AdminGateway::class );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_all_stories_url' )
			->with( 'tse_story' )
			->willReturn( 'https://example.test/stories' );

		$handler = new StoryReturnHandler(
			$this->createMock( PostAPI::class ),
			$this->createMock( Shorthand::class ),
			$admin_gateway,
			$this->createMock( StoryEditorLinkBuilder::class ),
			'tse_story'
		);

		$result = $handler->handle( null, 'story-123', null, null, 'page' );

		$this->assertTrue( $result->isError() );
		$this->assertStringContainsString( 'unexpected error', $result->getMessage() );
		$this->assertSame( 'https://example.test/stories', $result->getLinkUrl() );
		$this->assertSame( 'Return to all stories', $result->getLinkText() );
	}

	public function test_connects_story_and_redirects_back_to_the_editor(): void {
		$post_api      = $this->createMock( PostAPI::class );
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );
		$link_builder  = $this->createMock( StoryEditorLinkBuilder::class );

		$post = (object) array(
			'ID'         => 7,
			'post_title' => 'A story',
		);

		$post_api
			->expects( $this->once() )
			->method( 'connect_story' )
			->with( 'story-123', null )
			->willReturn( $post );

		$link_builder
			->expects( $this->once() )
			->method( 'build' )
			->with( 7, 'story-123' )
			->willReturn( 'https://example.test/editor?post=7' );

		$handler = new StoryReturnHandler( $post_api, $shorthand, $admin_gateway, $link_builder, 'tse_story' );

		$result = $handler->handle( null, 'story-123', null, null, 'tse_story' );

		$this->assertTrue( $result->isRedirect() );
		$this->assertSame( 'https://example.test/editor?post=7', $result->getRedirectUrl() );
	}

	public function test_updates_post_title_and_redirects_to_the_post_when_no_target_is_given(): void {
		$post_api      = $this->createMock( PostAPI::class );
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );
		$link_builder  = $this->createMock( StoryEditorLinkBuilder::class );

		$post = (object) array(
			'ID'         => 7,
			'post_title' => 'Old title',
		);

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_post' )
			->with( 7 )
			->willReturn( $post );

		$shorthand
			->expects( $this->once() )
			->method( 'get_story_title' )
			->with( 'story-123' )
			->willReturn( 'New title' );

		$admin_gateway
			->expects( $this->once() )
			->method( 'sync_post_title' )
			->with( $post, 'New title' );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_edit_post_link' )
			->with( 7 )
			->willReturn( 'https://example.test/post/7/edit' );

		$handler = new StoryReturnHandler( $post_api, $shorthand, $admin_gateway, $link_builder, 'tse_story' );

		$result = $handler->handle( 7, 'story-123', null, null, null );

		$this->assertTrue( $result->isRedirect() );
		$this->assertSame( 'https://example.test/post/7/edit', $result->getRedirectUrl() );
	}

	public function test_falls_back_to_the_stories_list_when_no_specific_target_exists(): void {
		$post_api      = $this->createMock( PostAPI::class );
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );
		$link_builder  = $this->createMock( StoryEditorLinkBuilder::class );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_post' )
			->with( 7 )
			->willReturn( null );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_all_stories_url' )
			->with( 'tse_story' )
			->willReturn( 'https://example.test/stories' );

		$handler = new StoryReturnHandler( $post_api, $shorthand, $admin_gateway, $link_builder, 'tse_story' );

		$result = $handler->handle( 7, null, null, null, null );

		$this->assertTrue( $result->isRedirect() );
		$this->assertSame( 'https://example.test/stories', $result->getRedirectUrl() );
	}
}
