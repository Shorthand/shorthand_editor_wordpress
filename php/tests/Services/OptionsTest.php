<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
use Shorthand\Services\Options;
use Shorthand\Tests\WordPressTestCase;

final class OptionsTest extends WordPressTestCase {

	public function test_activation_persists_story_as_the_default_permalink(): void {
		\tests_wp_set_option( 'shorthand_regex_list', '[]' );
		\tests_wp_set_option( 'shorthand_css', 'body {}' );

		$options = new Options( new Version() );
		$options->activate_plugin();

		$this->assertSame( 'story', \get_option( 'shorthand_permalink' ) );
	}

	public function test_legacy_synchronous_publish_option_is_dropped(): void {
		\tests_wp_set_option( 'shorthand_disable_cron', true );

		$options = new Options( new Version() );
		$options->remove_legacy_options();

		$this->assertFalse( \get_option( 'shorthand_disable_cron', false ) );
	}

	public function test_legacy_option_cleanup_is_a_no_op_on_a_clean_install(): void {
		$options = new Options( new Version() );
		$options->remove_legacy_options();

		$this->assertFalse( \get_option( 'shorthand_disable_cron', false ) );
	}

	public function test_permalink_changes_schedule_a_rewrite_flush(): void {
		$options = new Options( new Version() );

		$options->handle_permalink_added( 'shorthand_permalink', 'features' );
		$this->assertTrue( \get_option( 'shorthand_flush_rewrite_rules' ) );

		\delete_option( 'shorthand_flush_rewrite_rules' );

		$options->handle_permalink_updated( 'shorthand_permalink', 'story', 'features' );
		$this->assertTrue( \get_option( 'shorthand_flush_rewrite_rules' ) );
	}

	public function test_sanitize_regex_list_accepts_valid_rule_sets(): void {
		$options = new Options( new Version() );
		$rules   = '{"head":[{"query":"/<title>/","replace":"<title data-test=\\"true\\">"}],"body":[{"query":"/story/","replace":"article"}]}';

		$this->assertSame( $rules, $options->sanitize_regex_list( $rules ) );
		$this->assertSame( array(), \tests_wp_settings_errors() );
	}

	public function test_sanitize_regex_list_rejects_invalid_json(): void {
		$options = new Options( new Version() );
		$result  = $options->sanitize_regex_list( '{"head":' );

		$this->assertNull( $result );
		$this->assertSame(
			array(
				array(
					'setting' => 'shorthand_regex_list',
					'code'    => 'INVALID_REGEX_LIST',
					'message' => 'The post processing rules were invalid and could not be saved.',
				),
			),
			\tests_wp_settings_errors()
		);
	}

	public function test_sanitize_regex_list_rejects_rules_without_string_replacements(): void {
		$options = new Options( new Version() );
		$result  = $options->sanitize_regex_list( '{"body":[{"query":"/story/","replace":["article"]}]}' );

		$this->assertNull( $result );
		$this->assertSame(
			array(
				array(
					'setting' => 'shorthand_regex_list',
					'code'    => 'INVALID_REGEX_LIST',
					'message' => 'The post processing `body` rules should be an array of `query` and `replace` strings.',
				),
			),
			\tests_wp_settings_errors()
		);
	}
}
