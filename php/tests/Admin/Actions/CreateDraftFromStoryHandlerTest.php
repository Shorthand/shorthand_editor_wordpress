<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin\Actions;

use Shorthand\Admin\Actions\CreateDraftFromStoryHandler;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryAttachment;
use Shorthand\Services\StoryAttachmentResolver;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

final class CreateDraftFromStoryHandlerTest extends WordPressTestCase {

	public function test_returns_error_status_when_story_settings_cannot_be_fetched(): void {
		$shorthand   = $this->createMock( Shorthand::class );
		$post_api    = $this->createMock( PostAPI::class );
		$resolver    = $this->createMock( StoryAttachmentResolver::class );

		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn( new WP_Error( 'story', 'nope' ) );

		$post_api->expects( $this->never() )->method( 'connect_story' );
		$resolver->expects( $this->never() )->method( 'resolve' );

		$handler = new CreateDraftFromStoryHandler( $shorthand, $post_api, $resolver );
		$result  = $handler->handle( 'story-1' );

		$this->assertSame( CreateDraftFromStoryHandler::STATUS_ERROR, $result['status'] );
		$this->assertNull( $result['post_id'] );
	}

	public function test_creates_a_draft_when_the_story_is_attached_only_outside_this_instance(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$post_api  = $this->createMock( PostAPI::class );
		$resolver  = $this->createMock( StoryAttachmentResolver::class );

		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn(
			array(
				'external' => array( 'externalId' => '7' ),
			)
		);

		$resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'story-1', '7' )
			->willReturn( new StoryAttachment( null, true ) );

		$post = (object) array( 'ID' => 123 );

		$post_api
			->expects( $this->once() )
			->method( 'connect_story' )
			->with( 'story-1', null, 'draft' )
			->willReturn( $post );

		$handler = new CreateDraftFromStoryHandler( $shorthand, $post_api, $resolver );
		$result  = $handler->handle( 'story-1' );

		$this->assertSame( CreateDraftFromStoryHandler::STATUS_CREATED, $result['status'] );
		$this->assertSame( 123, $result['post_id'] );
	}

	public function test_refuses_to_create_a_draft_when_a_local_post_already_carries_the_story_id(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$post_api  = $this->createMock( PostAPI::class );
		$resolver  = $this->createMock( StoryAttachmentResolver::class );

		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn( array( 'external' => array() ) );

		$resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'story-1', null )
			->willReturn( new StoryAttachment( 42, false ) );

		$post_api->expects( $this->never() )->method( 'connect_story' );

		$handler = new CreateDraftFromStoryHandler( $shorthand, $post_api, $resolver );
		$result  = $handler->handle( 'story-1' );

		$this->assertSame( CreateDraftFromStoryHandler::STATUS_ALREADY_ATTACHED, $result['status'] );
		$this->assertSame( 42, $result['post_id'] );
	}

	public function test_creates_a_draft_post_when_the_story_is_unattached(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$post_api  = $this->createMock( PostAPI::class );
		$resolver  = $this->createMock( StoryAttachmentResolver::class );

		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn( array( 'external' => array() ) );

		$resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'story-1', null )
			->willReturn( new StoryAttachment( null, false ) );

		$post = (object) array( 'ID' => 123 );

		$post_api
			->expects( $this->once() )
			->method( 'connect_story' )
			->with( 'story-1', null, 'draft' )
			->willReturn( $post );

		$handler = new CreateDraftFromStoryHandler( $shorthand, $post_api, $resolver );
		$result  = $handler->handle( 'story-1' );

		$this->assertSame( CreateDraftFromStoryHandler::STATUS_CREATED, $result['status'] );
		$this->assertSame( 123, $result['post_id'] );
	}

	public function test_returns_error_status_when_post_creation_fails(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$post_api  = $this->createMock( PostAPI::class );
		$resolver  = $this->createMock( StoryAttachmentResolver::class );

		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn( array( 'external' => array() ) );
		$resolver->method( 'resolve' )->willReturn( new StoryAttachment( null, false ) );

		$post_api->method( 'connect_story' )->willReturn( new WP_Error( 'insert', 'failed' ) );

		$handler = new CreateDraftFromStoryHandler( $shorthand, $post_api, $resolver );
		$result  = $handler->handle( 'story-1' );

		$this->assertSame( CreateDraftFromStoryHandler::STATUS_ERROR, $result['status'] );
		$this->assertNull( $result['post_id'] );
	}

	public function test_treats_empty_string_external_id_as_absent(): void {
		$shorthand = $this->createMock( Shorthand::class );
		$post_api  = $this->createMock( PostAPI::class );
		$resolver  = $this->createMock( StoryAttachmentResolver::class );

		$shorthand->method( 'get_story_settings' )->with( 'story-1' )->willReturn(
			array( 'external' => array( 'externalId' => '' ) )
		);

		$resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'story-1', null )
			->willReturn( new StoryAttachment( null, false ) );

		$handler = new CreateDraftFromStoryHandler( $shorthand, $post_api, $resolver );
		$handler->handle( 'story-1' );
	}
}
