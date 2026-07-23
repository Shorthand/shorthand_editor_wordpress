<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryAttachmentResolver;
use Shorthand\Services\StoryLocalLookup;
use Shorthand\Tests\WordPressTestCase;

final class StoryAttachmentResolverTest extends WordPressTestCase {

	public function test_not_attached_when_no_local_post_and_no_external_id(): void {
		$local_lookup = $this->createMock( StoryLocalLookup::class );
		$local_lookup->method( 'find_post_id_by_story_id' )->with( 'story-1' )->willReturn( null );

		$resolver   = new StoryAttachmentResolver( $local_lookup );
		$attachment = $resolver->resolve( 'story-1', null );

		$this->assertFalse( $attachment->is_attached() );
		$this->assertNull( $attachment->get_local_post_id() );
		$this->assertFalse( $attachment->is_attached_elsewhere() );
	}

	public function test_external_id_without_local_post_is_attached_elsewhere_but_not_attached_here(): void {
		$local_lookup = $this->createMock( StoryLocalLookup::class );
		$local_lookup->method( 'find_post_id_by_story_id' )->with( 'story-1' )->willReturn( null );

		$resolver   = new StoryAttachmentResolver( $local_lookup );
		$attachment = $resolver->resolve( 'story-1', '99' );

		$this->assertFalse( $attachment->is_attached() );
		$this->assertNull( $attachment->get_local_post_id() );
		$this->assertTrue( $attachment->is_attached_elsewhere() );
	}

	public function test_attached_via_local_post_when_a_matching_post_exists(): void {
		$local_lookup = $this->createMock( StoryLocalLookup::class );
		$local_lookup->method( 'find_post_id_by_story_id' )->with( 'story-1' )->willReturn( 42 );

		$resolver   = new StoryAttachmentResolver( $local_lookup );
		$attachment = $resolver->resolve( 'story-1', null );

		$this->assertTrue( $attachment->is_attached() );
		$this->assertSame( 42, $attachment->get_local_post_id() );
		$this->assertFalse( $attachment->is_attached_elsewhere() );
	}

	public function test_local_post_takes_precedence_over_external_id(): void {
		$local_lookup = $this->createMock( StoryLocalLookup::class );
		$local_lookup->method( 'find_post_id_by_story_id' )->with( 'story-1' )->willReturn( 42 );

		$resolver   = new StoryAttachmentResolver( $local_lookup );
		$attachment = $resolver->resolve( 'story-1', '99' );

		$this->assertTrue( $attachment->is_attached() );
		$this->assertSame( 42, $attachment->get_local_post_id() );
		$this->assertFalse( $attachment->is_attached_elsewhere() );
	}

	public function test_empty_string_external_id_is_treated_as_unattached(): void {
		$local_lookup = $this->createMock( StoryLocalLookup::class );
		$local_lookup->method( 'find_post_id_by_story_id' )->with( 'story-1' )->willReturn( null );

		$resolver   = new StoryAttachmentResolver( $local_lookup );
		$attachment = $resolver->resolve( 'story-1', '' );

		$this->assertFalse( $attachment->is_attached() );
		$this->assertFalse( $attachment->is_attached_elsewhere() );
	}
}
