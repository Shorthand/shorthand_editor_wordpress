<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryCover;
use Shorthand\Tests\WordPressTestCase;
use WP_Error;
use WP_Post;

final class StoryCoverTest extends WordPressTestCase {

	private const POST_ID  = 7;
	private const STORY_ID = 'aBc123';

	public function test_a_story_without_a_cover_writes_nothing(): void {
		$cover = $this->make_story_cover( $this->settings( null ) );

		$this->assertSame( StoryCover::STATE_NONE, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );
		$this->assertSame( array(), \tests_wp_downloads() );
	}

	public function test_a_failed_settings_call_leaves_the_post_alone(): void {
		$cover = $this->make_story_cover( new WP_Error( 'http', 'timeout' ) );

		$this->assertSame( StoryCover::OUTCOME_FAILED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );
	}

	/**
	 * A featured image the plugin did not set is never replaced.
	 */
	public function test_a_featured_image_chosen_in_wordpress_is_kept(): void {
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 9 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::STATE_MANUAL, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_downloads() );
		$this->assertSame( 9, \get_post_thumbnail_id( self::POST_ID ) );
		$this->assertSame( 'c1', \get_post_meta( self::POST_ID, 'story_cover', true )['id'] );
		$this->assertSame( '', \get_post_meta( self::POST_ID, 'story_cover_attachment', true ) );
	}

	/**
	 * Once the author changes the plugin's featured image, the plugin stands
	 * down for good, even when the cover changes again.
	 */
	public function test_a_replaced_featured_image_is_not_overwritten(): void {
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 9 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c2' ) ) );

		$this->assertSame( StoryCover::STATE_OVERRIDDEN, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_downloads() );
		$this->assertSame( array(), \tests_wp_deleted_attachments() );
		$this->assertSame( 9, \get_post_thumbnail_id( self::POST_ID ) );
		$this->assertSame( 'c2', \get_post_meta( self::POST_ID, 'story_cover', true )['id'] );
	}

	public function test_a_removed_featured_image_counts_as_overridden(): void {
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::STATE_OVERRIDDEN, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_downloads() );
	}

	public function test_an_unchanged_cover_is_not_imported_again(): void {
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 300 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::STATE_CURRENT, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_downloads() );
		$this->assertSame( 300, \get_post_thumbnail_id( self::POST_ID ) );
	}

	public function test_the_first_cover_becomes_the_featured_image(): void {
		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ), 'A short summary.' ) );

		$this->assertSame( StoryCover::OUTCOME_IMPORTED, $cover->sync( self::POST_ID, self::STORY_ID ) );

		$this->assertSame( array( 'https://cdn.example.test/c1.jpg?sig=abc' ), \tests_wp_downloads() );
		$this->assertSame(
			array(
				array(
					'file'    => array(
						'name'     => 'cover.jpg',
						'tmp_name' => '/tmp/cover.tmp',
					),
					'post_id' => self::POST_ID,
				),
			),
			\tests_wp_sideloads()
		);
		$this->assertSame( 501, \get_post_thumbnail_id( self::POST_ID ) );
		$this->assertSame( 501, \get_post_meta( self::POST_ID, 'story_cover_attachment', true ) );
		$this->assertSame( 'c1', \get_post_meta( self::POST_ID, 'story_cover', true )['id'] );
		$this->assertSame( 'A short summary.', \get_post_meta( 501, '_wp_attachment_image_alt', true ) );
		$this->assertSame( array(), \tests_wp_deleted_attachments() );
		$this->assertSame( array(), \tests_wp_deleted_files() );
	}

	public function test_a_cover_without_a_name_is_named_from_its_url(): void {
		$record = $this->cover( 'c1' );
		unset( $record['name'] );

		$this->make_story_cover( $this->settings( $record ) )->sync( self::POST_ID, self::STORY_ID );

		$this->assertSame( 'c1.jpg', \tests_wp_sideloads()[0]['file']['name'] );
	}

	public function test_a_blank_description_leaves_the_alt_text_unset(): void {
		$this->make_story_cover( $this->settings( $this->cover( 'c1' ), '' ) )->sync( self::POST_ID, self::STORY_ID );

		$this->assertSame( '', \get_post_meta( 501, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The old attachment is the plugin's own, so it does not linger in the
	 * media library. It is removed after the thumbnail moves, because deleting
	 * it would otherwise clear the post's `_thumbnail_id`.
	 */
	public function test_a_changed_cover_replaces_the_previous_attachment(): void {
		\tests_wp_set_post( 300, new WP_Post( array( 'ID' => 300, 'post_type' => 'attachment' ) ) );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 300 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c2' ) ) );

		$this->assertSame( StoryCover::OUTCOME_IMPORTED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame(
			array(
				array(
					'post_id' => 300,
					'force'   => true,
				),
			),
			\tests_wp_deleted_attachments()
		);
		$this->assertSame( 501, \get_post_thumbnail_id( self::POST_ID ) );
		$this->assertSame( 501, \get_post_meta( self::POST_ID, 'story_cover_attachment', true ) );
		$this->assertSame( 'c2', \get_post_meta( self::POST_ID, 'story_cover', true )['id'] );
	}

	public function test_a_cover_that_is_not_an_image_is_refused(): void {
		$record         = $this->cover( 'c1' );
		$record['mime'] = 'application/pdf';

		$cover = $this->make_story_cover( $this->settings( $record ) );

		$this->assertSame( StoryCover::OUTCOME_FAILED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_downloads() );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );
	}

	public function test_a_cover_over_the_size_limit_is_refused(): void {
		$record         = $this->cover( 'c1' );
		$record['size'] = 20 * MB_IN_BYTES + 1;

		$cover = $this->make_story_cover( $this->settings( $record ) );

		$this->assertSame( StoryCover::OUTCOME_FAILED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_downloads() );
		$this->assertCount( 1, \tests_wp_get_filter_args( 'theshed_cover_max_bytes' ) );
	}

	public function test_a_failed_download_writes_nothing(): void {
		\tests_wp_set_download_result( new WP_Error( 'http_404', 'Not found' ) );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::OUTCOME_FAILED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array(), \tests_wp_sideloads() );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );
		$this->assertSame( 0, \get_post_thumbnail_id( self::POST_ID ) );
	}

	public function test_a_failed_sideload_removes_the_download(): void {
		\tests_wp_set_sideload_result( new WP_Error( 'upload_error', 'Not writable' ) );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::OUTCOME_FAILED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( array( '/tmp/cover.tmp' ), \tests_wp_deleted_files() );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );
	}

	public function test_a_cached_fetch_makes_no_request_and_writes_no_meta(): void {
		\tests_wp_set_transient( 'shorthand_story_cover_' . self::STORY_ID, array( 'cover' => $this->cover( 'c1' ) ) );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->never() )->method( 'get_story_settings' );

		$cover = ( new StoryCover( $shorthand ) )->fetch( self::STORY_ID, true );

		$this->assertSame( 'c1', $cover['id'] );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );
	}

	public function test_a_cached_empty_cover_is_reused(): void {
		\tests_wp_set_transient( 'shorthand_story_cover_' . self::STORY_ID, array( 'cover' => null ) );

		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->never() )->method( 'get_story_settings' );

		$this->assertNull( ( new StoryCover( $shorthand ) )->fetch( self::STORY_ID, true ) );
	}

	public function test_an_uncached_fetch_fills_the_cache(): void {
		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( 'c1', $cover->fetch( self::STORY_ID, true )['id'] );
		$this->assertSame( 'c1', \tests_wp_get_transient( 'shorthand_story_cover_' . self::STORY_ID )['cover']['id'] );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, \tests_wp_get_transient_ttl( 'shorthand_story_cover_' . self::STORY_ID ) );
	}

	/**
	 * The editor refresh sees a cover before publishing does. That must not
	 * make the publish think the cover was already imported.
	 */
	public function test_a_cover_seen_by_the_editor_is_still_imported_on_publish(): void {
		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ), 2 );

		$cover->fetch( self::STORY_ID );
		$this->assertSame( array(), \tests_wp_updated_post_meta() );

		$this->assertSame( StoryCover::OUTCOME_IMPORTED, $cover->sync( self::POST_ID, self::STORY_ID ) );
		$this->assertSame( 501, \get_post_thumbnail_id( self::POST_ID ) );
	}

	/**
	 * Removing a featured image chosen in WordPress leaves the post with none,
	 * so the plugin may set the cover on the next publish.
	 */
	public function test_removing_a_manual_featured_image_hands_control_back(): void {
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::STATE_PENDING, $cover->state( self::POST_ID, $this->cover( 'c1' ) ) );
		$this->assertSame( StoryCover::OUTCOME_IMPORTED, $cover->sync( self::POST_ID, self::STORY_ID ) );
	}

	/**
	 * The classic editor holds an unsaved featured image choice in the form,
	 * so the state is judged against the id the caller passes.
	 */
	public function test_state_judges_the_thumbnail_it_is_given(): void {
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 300 );

		$cover = new StoryCover( $this->createMock( Shorthand::class ) );

		$this->assertSame( StoryCover::STATE_CURRENT, $cover->state( self::POST_ID, $this->cover( 'c1' ) ) );
		$this->assertSame( StoryCover::STATE_CURRENT, $cover->state( self::POST_ID, $this->cover( 'c1' ), 300 ) );
		$this->assertSame( StoryCover::STATE_OVERRIDDEN, $cover->state( self::POST_ID, $this->cover( 'c1' ), 0 ) );
		$this->assertSame( StoryCover::STATE_OVERRIDDEN, $cover->state( self::POST_ID, $this->cover( 'c1' ), 9 ) );
	}

	public function test_a_replacing_import_overrides_a_featured_image_chosen_in_wordpress(): void {
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 9 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::OUTCOME_IMPORTED, $cover->sync( self::POST_ID, self::STORY_ID, true ) );
		$this->assertSame( 501, \get_post_thumbnail_id( self::POST_ID ) );
		$this->assertSame( 501, \get_post_meta( self::POST_ID, 'story_cover_attachment', true ) );
		$this->assertSame( array(), \tests_wp_deleted_attachments() );
	}

	/**
	 * An attachment the author already moved away from may be in use
	 * elsewhere, so a replacing import leaves it in the library.
	 */
	public function test_a_replacing_import_keeps_an_overridden_attachment(): void {
		\tests_wp_set_post( 300, new WP_Post( array( 'ID' => 300, 'post_type' => 'attachment' ) ) );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 9 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c2' ) ) );

		$this->assertSame( StoryCover::OUTCOME_IMPORTED, $cover->sync( self::POST_ID, self::STORY_ID, true ) );
		$this->assertSame( 501, \get_post_thumbnail_id( self::POST_ID ) );
		$this->assertSame( 501, \get_post_meta( self::POST_ID, 'story_cover_attachment', true ) );
		$this->assertSame( array(), \tests_wp_deleted_attachments() );
	}

	public function test_a_replacing_import_skips_a_cover_that_is_already_featured(): void {
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover_attachment', 300 );
		\tests_wp_set_post_meta( self::POST_ID, 'story_cover', $this->cover( 'c1' ) );
		\tests_wp_set_post_meta( self::POST_ID, '_thumbnail_id', 300 );

		$cover = $this->make_story_cover( $this->settings( $this->cover( 'c1' ) ) );

		$this->assertSame( StoryCover::STATE_CURRENT, $cover->sync( self::POST_ID, self::STORY_ID, true ) );
		$this->assertSame( array(), \tests_wp_downloads() );
	}

	public function test_sanitize_keeps_only_the_known_keys(): void {
		$this->assertSame(
			array(
				'id'     => 'c1',
				'url'    => 'https://cdn.example.test/c1.jpg?sig=abc',
				'mime'   => 'image/jpeg',
				'name'   => 'cover.jpg',
				'size'   => 1200,
				'width'  => 800,
				'height' => 600,
			),
			StoryCover::sanitize( $this->cover( 'c1' ) + array( 'extra' => 'dropped' ) )
		);
		$this->assertNull( StoryCover::sanitize( null ) );
		$this->assertNull( StoryCover::sanitize( 'c1' ) );
		$this->assertNull( StoryCover::sanitize( array( 'id' => 'c1' ) ) );
		$this->assertNull( StoryCover::sanitize( array( 'url' => 'https://cdn.example.test/c1.jpg' ) ) );
	}

	/**
	 * The API reports `signedUrl`; meta already holds the reduced shape with
	 * `url`. Both pass the same sanitizer.
	 */
	public function test_sanitize_accepts_the_stored_shape_as_well_as_the_api_shape(): void {
		$stored = StoryCover::sanitize( $this->cover( 'c1' ) );

		$this->assertSame( $stored, StoryCover::sanitize( $stored ) );
		$this->assertNull( StoryCover::sanitize( array( 'id' => 'c1', 'signedUrl' => '' ) ) );
	}

	/**
	 * @param array|WP_Error $settings
	 */
	private function make_story_cover( $settings, int $calls = 1 ): StoryCover {
		$shorthand = $this->createMock( Shorthand::class );
		$shorthand->expects( $this->exactly( $calls ) )
			->method( 'get_story_settings' )
			->with( self::STORY_ID )
			->willReturn( $settings );

		return new StoryCover( $shorthand );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settings( ?array $cover, string $description = 'Described.' ): array {
		return array(
			'id'   => self::STORY_ID,
			'meta' => array(
				'cover'       => $cover,
				'description' => $description,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cover( string $id ): array {
		return array(
			'id'        => $id,
			'signedUrl' => "https://cdn.example.test/{$id}.jpg?sig=abc",
			'mime'   => 'image/jpeg',
			'name'   => 'cover.jpg',
			'size'   => 1200,
			'width'  => 800,
			'height' => 600,
		);
	}
}
