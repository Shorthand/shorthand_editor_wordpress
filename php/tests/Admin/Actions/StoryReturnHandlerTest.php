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

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'https://example.test/post/7', $result->get_link_url() );
		$this->assertSame( 'Return to story', $result->get_link_text() );
		$this->assertNull( $result->get_secondary_link_url() );
		$this->assertStringContainsString( 'oops', $result->get_message() );
		$this->assertStringContainsString( 'Your story is safe', $result->get_message() );
	}

	public function test_navigation_error_with_story_id_offers_reopen_and_fallback(): void {
		$post_api      = $this->createMock( PostAPI::class );
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );
		$link_builder  = $this->createMock( StoryEditorLinkBuilder::class );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_edit_post_link' )
			->with( 7 )
			->willReturn( 'https://example.test/post/7/edit' );

		$link_builder
			->expects( $this->once() )
			->method( 'build' )
			->with( 7, 'story-123' )
			->willReturn( 'https://example.test/reopen' );

		$handler = new StoryReturnHandler( $post_api, $shorthand, $admin_gateway, $link_builder, 'tse_story' );

		$result = $handler->handle( 7, 'story-123', 'timeout', null, null );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'https://example.test/reopen', $result->get_link_url() );
		$this->assertSame( 'Reopen story in Shorthand', $result->get_link_text() );
		$this->assertSame( 'https://example.test/post/7/edit', $result->get_secondary_link_url() );
		$this->assertSame( 'Return to story', $result->get_secondary_link_text() );
		$this->assertStringContainsString( 'timeout', $result->get_message() );
	}

	public function test_navigation_error_during_story_creation_mentions_creation(): void {
		$post_api      = $this->createMock( PostAPI::class );
		$shorthand     = $this->createMock( Shorthand::class );
		$admin_gateway = $this->createMock( AdminGateway::class );
		$link_builder  = $this->createMock( StoryEditorLinkBuilder::class );

		$admin_gateway
			->expects( $this->once() )
			->method( 'get_all_stories_url' )
			->with( 'tse_story' )
			->willReturn( 'https://example.test/stories' );

		$handler = new StoryReturnHandler( $post_api, $shorthand, $admin_gateway, $link_builder, 'tse_story' );

		$result = $handler->handle( null, null, 'forbidden', null, 'tse_story' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'https://example.test/stories', $result->get_link_url() );
		$this->assertSame( 'Return to all stories', $result->get_link_text() );
		$this->assertStringContainsString( 'creating your story', $result->get_message() );
		$this->assertStringContainsString( 'forbidden', $result->get_message() );
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

		$this->assertTrue( $result->is_error() );
		$this->assertStringContainsString( 'different content type', $result->get_message() );
		$this->assertStringContainsString( 'No content was lost', $result->get_message() );
		$this->assertSame( 'https://example.test/stories', $result->get_link_url() );
		$this->assertSame( 'Return to all stories', $result->get_link_text() );
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

		$this->assertTrue( $result->is_redirect() );
		$this->assertSame( 'https://example.test/editor?post=7', $result->get_redirect_url() );
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

		$this->assertTrue( $result->is_redirect() );
		$this->assertSame( 'https://example.test/post/7/edit', $result->get_redirect_url() );
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

		$this->assertTrue( $result->is_redirect() );
		$this->assertSame( 'https://example.test/stories', $result->get_redirect_url() );
	}
}
