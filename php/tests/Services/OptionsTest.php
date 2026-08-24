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

	/**
	 * The cron override is read as a plain truthy value.
	 *
	 * @dataProvider cron_option_values
	 *
	 * @param mixed $value    Value the option is left at.
	 * @param bool  $expected Whether publishing should be scheduled on WP-Cron.
	 */
	public function test_the_cron_override_decides_whether_publishing_is_asynchronous( $value, bool $expected ): void {
		\tests_wp_set_option( 'shorthand_disable_cron', $value );

		$options = new Options( new Version() );

		$this->assertSame( $expected, $options->is_publishing_async() );
	}

	/**
	 * A checkbox left unticked stores a falsy value, not an absent row.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function cron_option_values(): array {
		return array(
			'turned on'  => array( true, false ),
			'turned off' => array( false, true ),
			'unchecked'  => array( '', true ),
		);
	}

	public function test_publishing_is_asynchronous_on_a_clean_install(): void {
		$options = new Options( new Version() );

		$this->assertTrue( $options->is_publishing_async() );
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

	public function test_staging_is_on_by_default(): void {
		$options = new Options( new Version() );

		$this->assertTrue( $options->is_staging_enabled() );
		$this->assertTrue( $options->can_disable_staging() );
	}

	public function test_staging_can_be_turned_off_where_uploads_are_local(): void {
		update_option( 'shorthand_disable_staging', true );

		$options = new Options( new Version() );

		$this->assertFalse( $options->is_staging_enabled() );
	}

	/**
	 * Unpacking cannot target a stream wrapper, so the choice is withdrawn.
	 */
	public function test_staging_cannot_be_turned_off_where_uploads_are_remote(): void {
		\tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/uploads' );
		update_option( 'shorthand_disable_staging', true );

		$options = new Options( new Version() );

		$this->assertFalse( $options->can_disable_staging() );
		$this->assertTrue( $options->is_staging_enabled() );
	}

	public function test_sanitize_checkbox_reads_an_absent_box_as_off(): void {
		$options = new Options( new Version() );

		$this->assertTrue( $options->sanitize_checkbox( '1' ) );
		$this->assertFalse( $options->sanitize_checkbox( '' ) );
		$this->assertFalse( $options->sanitize_checkbox( null ) );
	}
}
