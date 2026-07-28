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

	private function make_post_type(): PostType {
		return new PostType(
			$this->createStub( Options::class ),
			new Version()
		);
	}
}
