<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Core\Version;
use Shorthand\Plugin\PostType;
use Shorthand\Services\Options;
use Shorthand\Tests\WordPressTestCase;

final class PostTypeTest extends WordPressTestCase {

	public function test_pending_rewrite_flush_runs_after_post_type_registration(): void {
		\tests_wp_set_option( 'shorthand_flush_rewrite_rules', true );

		$post_type = $this->make_post_type();
		\tests_wp_register_post_type( $post_type->post_type );

		$post_type->maybe_flush_rewrite_rules();

		$this->assertSame( 1, \tests_wp_rewrite_flushes() );
		$this->assertFalse( \get_option( 'shorthand_flush_rewrite_rules', false ) );
	}

	public function test_rewrite_rules_are_not_flushed_without_a_pending_change(): void {
		$post_type = $this->make_post_type();
		\tests_wp_register_post_type( $post_type->post_type );

		$post_type->maybe_flush_rewrite_rules();

		$this->assertSame( 0, \tests_wp_rewrite_flushes() );
	}

	public function test_pending_rewrite_flush_is_retried_while_the_post_type_is_unregistered(): void {
		\tests_wp_set_option( 'shorthand_flush_rewrite_rules', true );

		$post_type = $this->make_post_type();
		$post_type->maybe_flush_rewrite_rules();

		$this->assertSame( 0, \tests_wp_rewrite_flushes() );
		$this->assertNotFalse(
			\get_option( 'shorthand_flush_rewrite_rules', false ),
			'The pending flag must survive so the flush is retried on a later request.'
		);
	}

	/**
	 * The `story_id` meta value is interpolated into a file system path, so the
	 * meta layer must reject a path-shaped value rather than store it.
	 */
	public function test_story_id_meta_rejects_a_value_that_is_not_a_path_segment(): void {
		$post_type = $this->make_post_type();
		$post_type->register_post_type();

		$args = \tests_wp_registered_post_meta( $post_type->post_type, 'story_id' );
		$this->assertArrayHasKey( 'sanitize_callback', $args );

		$sanitize = $args['sanitize_callback'];

		$this->assertSame( '', $sanitize( '../../etc/passwd' ) );
		$this->assertSame( '', $sanitize( '/etc/passwd' ) );
		$this->assertSame( '', $sanitize( 'abc\\def' ) );
		$this->assertSame( '', $sanitize( 'abc.def' ) );
		$this->assertSame( '', $sanitize( "abc\0def" ) );
		$this->assertSame( 'aBc123', $sanitize( 'aBc123' ) );
	}

	public function test_story_cover_meta_keeps_only_the_known_keys(): void {
		$post_type = $this->make_post_type();
		$post_type->register_post_type();

		$sanitize = \tests_wp_registered_post_meta( $post_type->post_type, 'story_cover' )['sanitize_callback'];

		$this->assertSame(
			array(
				'id'     => 'c1',
				'url'    => 'https://cdn.example.test/c1.jpg',
				'mime'   => 'image/jpeg',
				'name'   => 'cover.jpg',
				'size'   => 12,
				'width'  => 800,
				'height' => 600,
			),
			$sanitize(
				array(
					'id'     => 'c1',
					'url'    => 'https://cdn.example.test/c1.jpg',
					'mime'   => 'image/jpeg',
					'name'   => 'cover.jpg',
					'size'   => '12',
					'width'  => 800,
					'height' => 600,
					'extra'  => 'dropped',
				)
			)
		);
		$this->assertNull( $sanitize( 'not a cover' ) );
		$this->assertNull( $sanitize( array( 'id' => 'c1' ) ) );
	}

	public function test_story_cover_attachment_meta_is_an_integer(): void {
		$post_type = $this->make_post_type();
		$post_type->register_post_type();

		$args = \tests_wp_registered_post_meta( $post_type->post_type, 'story_cover_attachment' );

		$this->assertSame( 'integer', $args['type'] );
		$this->assertSame( 'absint', $args['sanitize_callback'] );
	}

	/**
	 * Both keys drive the featured image rule, so the Custom Fields box must
	 * not offer them for editing.
	 */
	public function test_cover_meta_keys_are_protected(): void {
		$post_type = $this->make_post_type();

		$this->assertTrue( $post_type->is_protected_meta( false, 'story_cover', 'post' ) );
		$this->assertTrue( $post_type->is_protected_meta( false, 'story_cover_attachment', 'post' ) );
		$this->assertFalse( $post_type->is_protected_meta( false, 'story_cover', 'user' ) );
	}

	private function make_post_type(): PostType {
		return new PostType(
			$this->createStub( Options::class ),
			new Version()
		);
	}
}
