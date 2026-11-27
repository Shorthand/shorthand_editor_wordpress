<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\StoryListColumn;
use Shorthand\Admin\StorySaveIntent;
use Shorthand\Core\Loader;
use Shorthand\Services\PostAPI;
use Shorthand\Services\StorySyncProgress;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;

/**
 * Tests the "Story" list column and the Quick Edit and bulk edit intent field.
 */
final class StoryListColumnTest extends WordPressTestCase {

	public function test_the_column_sits_before_the_date(): void {
		$columns = $this->make_column()->add_column(
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', StoryListColumn::COLUMN, 'date' ), array_keys( $columns ) );
		$this->assertSame( 'Story', $columns[ StoryListColumn::COLUMN ] );
	}

	public function test_the_column_goes_last_without_a_date_column(): void {
		$columns = $this->make_column()->add_column( array( 'title' => 'Title' ) );

		$this->assertSame( array( 'title', StoryListColumn::COLUMN ), array_keys( $columns ) );
	}

	public function test_the_column_shows_each_update_state(): void {
		$post_api = $this->real_post_api();
		$column   = $this->make_column( $post_api );

		$this->assertSame( '', $column->describe( 7 ) );

		$post_api->set_post_story_version( 7, 3 );
		$this->assertSame( 'Fetched', $column->describe( 7 ) );

		$post_api->set_story_update_progress( 7, new StorySyncProgress( 39.6, 'Downloading' ) );
		$this->assertSame( 'Updating 40%', $column->describe( 7 ) );

		$post_api->set_story_update_error( 7, new WP_Error( 'stalled', 'Stopped.' ) );
		$this->assertSame( 'Update failed', $column->describe( 7 ) );
	}

	public function test_a_story_never_fetched_shows_a_dash(): void {
		ob_start();
		$this->make_column()->render_column( StoryListColumn::COLUMN, 7 );

		$this->assertSame( '<span aria-hidden="true">&#8212;</span>', ob_get_clean() );
	}

	public function test_other_columns_are_left_alone(): void {
		ob_start();
		$column = $this->make_column();
		$column->render_column( 'title', 7 );
		$column->render_intent_field( 'title', 'tse_story' );

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Core clones the template for each row, so the field has no id.
	 */
	public function test_quick_edit_and_bulk_edit_get_the_list_intent_field(): void {
		ob_start();
		$this->make_column()->render_intent_field( StoryListColumn::COLUMN, 'tse_story' );
		$html = (string) ob_get_clean();

		$this->assertSame( '<input type="hidden" name="' . StorySaveIntent::FIELD . '" value="test-nonce" />', $html );
		$this->assertStringNotContainsString( 'id=', $html );
	}

	public function test_another_post_type_list_gets_no_intent_field(): void {
		ob_start();
		$this->make_column()->render_intent_field( StoryListColumn::COLUMN, 'post' );

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_hooks_are_registered_for_the_story_post_type(): void {
		$loader = new Loader();
		$this->make_column()->init( $loader );
		$loader->register();

		foreach ( array( 'manage_tse_story_posts_columns', 'manage_tse_story_posts_custom_column', 'quick_edit_custom_box', 'bulk_edit_custom_box' ) as $hook ) {
			$this->assertCount( 1, \tests_wp_hook_callbacks( $hook ), $hook );
		}
	}

	private function make_column( ?PostAPI $post_api = null ): StoryListColumn {
		return new StoryListColumn( $post_api ?? $this->real_post_api(), 'tse_story' );
	}

	/**
	 * A PostAPI that reads and writes the stubbed post meta.
	 */
	private function real_post_api(): PostAPI {
		$post_api = $this->instantiateWithoutConstructor( PostAPI::class );
		\assert( $post_api instanceof PostAPI );
		return $post_api;
	}
}
