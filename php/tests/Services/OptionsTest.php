<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Version;
use Shorthand\Services\Options;
use Shorthand\Tests\WordPressTestCase;

final class OptionsTest extends WordPressTestCase {

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
