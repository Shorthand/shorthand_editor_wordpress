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
	 * The synchronous publish override goes, whatever it was left at.
	 *
	 * @dataProvider legacy_option_values
	 *
	 * @param mixed $value Value the option was left at.
	 */
	public function test_legacy_synchronous_publish_option_is_dropped( $value ): void {
		\tests_wp_set_option( 'shorthand_disable_cron', $value );

		$options = new Options( new Version() );
		$options->remove_legacy_options();

		$this->assertSame( 'absent', \get_option( 'shorthand_disable_cron', 'absent' ) );
	}

	/**
	 * Staging is no longer optional, so the option that turned it off goes.
	 *
	 * @dataProvider legacy_option_values
	 *
	 * @param mixed $value Value the option was left at.
	 */
	public function test_legacy_staging_option_is_dropped( $value ): void {
		\tests_wp_set_option( 'shorthand_disable_staging', $value );

		$options = new Options( new Version() );
		$options->remove_legacy_options();

		$this->assertSame( 'absent', \get_option( 'shorthand_disable_staging', 'absent' ) );
	}

	/**
	 * A turned-off override is still an option row, and still goes.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function legacy_option_values(): array {
		return array(
			'turned on'  => array( true ),
			'turned off' => array( false ),
			'unchecked'  => array( '' ),
		);
	}

	public function test_legacy_option_cleanup_is_a_no_op_on_a_clean_install(): void {
		$options = new Options( new Version() );
		$options->remove_legacy_options();

		$this->assertSame( 'absent', \get_option( 'shorthand_disable_cron', 'absent' ) );
		$this->assertSame( 'absent', \get_option( 'shorthand_disable_staging', 'absent' ) );
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

	/**
	 * The settings screen escapes on output, so the getters owe it plain text.
	 *
	 * @dataProvider entity_encoded_names
	 */
	public function test_workspace_and_team_names_are_returned_as_plain_text( string $stored, string $expected ): void {
		\tests_wp_set_option(
			'shorthand_v2_token_info',
			array(
				'workspace' => $stored,
				'name'      => $stored,
			)
		);

		$options = new Options( new Version() );

		$this->assertSame( $expected, $options->get_token_org_name() );
		$this->assertSame( $expected, $options->get_token_name() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function entity_encoded_names(): array {
		return array(
			'apostrophe' => array( 'Don&#039;t Call Me', "Don't Call Me" ),
			'ampersand'  => array( 'Rock &amp; Roll', 'Rock & Roll' ),
			'quotes'     => array( '&quot;Scare&quot; Quotes', '"Scare" Quotes' ),
			'plain text' => array( 'Newsroom', 'Newsroom' ),
		);
	}

	public function test_token_info_getters_tolerate_a_partial_api_payload(): void {
		$options = new Options( new Version() );

		$this->assertSame(
			array(
				'team_id'         => '',
				'organisation_id' => '',
				'workspace'       => 'Newsroom',
				'name'            => '',
				'logo'            => '',
				'token_type'      => '',
			),
			$options->sanitize_v2_token_info( array( 'workspace' => 'Newsroom' ) )
		);
	}

	public function test_token_info_rejects_a_non_array_payload(): void {
		$options = new Options( new Version() );

		$this->assertNull( $options->sanitize_v2_token_info( 'nonsense' ) );
	}

	/**
	 * A host that cannot boot `WP_Filesystem` still renders the settings
	 * screen, so the bundled stylesheet comes back empty rather than fatal.
	 */
	public function test_the_default_css_is_empty_when_the_file_system_cannot_boot(): void {
		$this->forgetFileSystemBoot();
		\tests_wp_set_filesystem_available( false );

		$options = new Options( new Version() );

		$this->assertSame( '', $options->get_default_css() );
	}
}
