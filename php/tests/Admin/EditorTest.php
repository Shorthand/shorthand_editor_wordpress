<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\Actions\EditWithShorthand;
use Shorthand\Admin\Actions\PostPreview;
use Shorthand\Admin\Editor;
use Shorthand\Core\Version;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Cron;
use Shorthand\Services\Options;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Tests\WordPressTestCase;

final class EditorTest extends WordPressTestCase {

	/**
	 * Publishing is scheduled in `wp_insert_post_data()`. Saving a published
	 * post must not pull the story a second time.
	 *
	 * @dataProvider publishing_statuses
	 */
	public function test_saving_a_published_post_does_not_publish_the_story( string $post_status ): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->never() )->method( 'pull_story_now' );
		$post_api->expects( $this->never() )->method( 'extract_story_content' );
		$post_api->expects( $this->never() )->method( 'set_post_story_version' );

		$editor = $this->make_editor( $post_api );
		$editor->save_shorthand_story( 7, $this->make_post( $post_status ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function publishing_statuses(): array {
		return array(
			'published' => array( 'publish' ),
			'scheduled' => array( 'future' ),
		);
	}

	public function test_saving_an_unpublished_post_clears_the_recorded_story_version(): void {
		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->once() )->method( 'set_post_story_version' )->with( 7, null );

		$editor = $this->make_editor( $post_api );
		$editor->save_shorthand_story( 7, $this->make_post( 'draft' ) );
	}

	public function test_disabling_cron_publishes_the_story_in_the_saving_request(): void {
		tests_wp_set_post_meta( 7, 'story_id', 'abc123' );

		$post_api = $this->createMock( PostAPI::class );
		$post_api->expects( $this->once() )
			->method( 'pull_story_now' )
			->with( 'abc123', 7 )
			->willReturn( 42 );
		$post_api->expects( $this->once() )->method( 'set_post_story_version' )->with( 7, 42 );

		$editor = $this->make_editor( $post_api, false );
		$editor->save_shorthand_story( 7, $this->make_post( 'publish' ) );
	}

	public function test_a_failed_synchronous_publish_records_the_error_and_halts_the_request(): void {
		tests_wp_set_post_meta( 7, 'story_id', 'abc123' );

		$error    = new \WP_Error( 'shorthand_api', 'Story not found' );
		$post_api = $this->createMock( PostAPI::class );
		$post_api->method( 'pull_story_now' )->willReturn( $error );
		$post_api->expects( $this->once() )->method( 'set_story_update_error' )->with( 7, $error );
		$post_api->expects( $this->never() )->method( 'set_post_story_version' );

		$editor = $this->make_editor( $post_api, false );

		$this->expectException( \Tests_WP_Die_Exception::class );
		$this->expectExceptionMessage( 'Story not found' );
		$editor->save_shorthand_story( 7, $this->make_post( 'publish' ) );
	}

	private function make_post( string $post_status ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => 7,
				'post_status' => $post_status,
				'post_type'   => 'tse_story',
			)
		);
	}

	private function make_editor( PostAPI $post_api, bool $publishing_async = true ): Editor {
		$options = $this->createMock( Options::class );
		$options->method( 'is_publishing_async' )->willReturn( $publishing_async );

		return new Editor(
			$options,
			$this->createMock( Shorthand::class ),
			$this->createMock( Cron::class ),
			new Version(),
			$post_api,
			$this->createMock( PostPreview::class ),
			$this->createMock( EditWithShorthand::class ),
			'tse_story',
			$this->createMock( AuthStateManager::class )
		);
	}
}
