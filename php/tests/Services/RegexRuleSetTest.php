<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\RegexRuleSet;
use Shorthand\Tests\WordPressTestCase;

final class RegexRuleSetTest extends WordPressTestCase {

	public function test_from_json_builds_rules_that_can_transform_head_and_body_content(): void {
		$rule_set = RegexRuleSet::from_json(
			'{"head":[{"query":"/<title>/","replace":"<title data-test=\\"true\\">"}],"body":[{"query":"/draft/","replace":"published"}]}'
		);

		$this->assertInstanceOf( RegexRuleSet::class, $rule_set );
		$this->assertSame( '<title data-test="true">Story</title>', $rule_set->apply_to_head( '<title>Story</title>' ) );
		$this->assertSame( '<p>published story</p>', $rule_set->apply_to_body( '<p>draft story</p>' ) );
	}

	public function test_get_validation_error_reports_invalid_body_rules(): void {
		$this->assertSame(
			'The post processing `body` rules should be an array of `query` and `replace` strings.',
			RegexRuleSet::get_validation_error( '{"body":[{"query":"/story/","replace":["article"]}]}' )
		);
	}
}
