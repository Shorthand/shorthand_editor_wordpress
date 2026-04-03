<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RegexRuleSet {

	/**
	 * @var \Shorthand\Services\RegexReplacementRule[]
	 */
	private $head_rules;

	/**
	 * @var \Shorthand\Services\RegexReplacementRule[]
	 */
	private $body_rules;

	/**
	 * @param \Shorthand\Services\RegexReplacementRule[] $head_rules
	 * @param \Shorthand\Services\RegexReplacementRule[] $body_rules
	 */
	public function __construct( array $head_rules = array(), array $body_rules = array() ) {
		$this->head_rules = $head_rules;
		$this->body_rules = $body_rules;
	}

	public static function from_json( string $rules_json ): ?self {
		$parsed = self::parse_json( $rules_json );
		return $parsed['rule_set'];
	}

	public static function get_validation_error( string $rules_json ): ?string {
		$parsed = self::parse_json( $rules_json );
		return $parsed['error'];
	}

	public function apply_to_head( string $content ): string {
		return $this->apply_rules( $content, $this->head_rules );
	}

	public function apply_to_body( string $content ): string {
		return $this->apply_rules( $content, $this->body_rules );
	}

	/**
	 * @param \Shorthand\Services\RegexReplacementRule[] $rules
	 */
	private function apply_rules( string $content, array $rules ): string {
		return array_reduce(
			$rules,
			static function ( string $carry, RegexReplacementRule $rule ): string {
				return $rule->apply( $carry );
			},
			$content
		);
	}

	/**
	 * @return array{rule_set: ?self, error: ?string}
	 */
	private static function parse_json( string $rules_json ): array {
		$rules_json = trim( $rules_json );

		if ( '' === $rules_json ) {
			return array(
				'rule_set' => new self(),
				'error'    => null,
			);
		}

		$object = json_decode( $rules_json );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $object ) ) {
			return array(
				'rule_set' => null,
				'error'    => 'The post processing rules were invalid and could not be saved.',
			);
		}

		$head_rules = self::parse_rule_group( $object, 'head' );
		if ( null !== $head_rules['error'] ) {
			return array(
				'rule_set' => null,
				'error'    => $head_rules['error'],
			);
		}

		$body_rules = self::parse_rule_group( $object, 'body' );
		if ( null !== $body_rules['error'] ) {
			return array(
				'rule_set' => null,
				'error'    => $body_rules['error'],
			);
		}

		return array(
			'rule_set' => new self( $head_rules['rules'], $body_rules['rules'] ),
			'error'    => null,
		);
	}

	/**
	 * @return array{rules: \Shorthand\Services\RegexReplacementRule[], error: ?string}
	 */
	private static function parse_rule_group( object $object, string $group_name ): array {
		if ( ! isset( $object->{$group_name} ) ) {
			return array(
				'rules' => array(),
				'error' => null,
			);
		}

		if ( ! is_array( $object->{$group_name} ) ) {
			return array(
				'rules' => array(),
				'error' => "The post processing `{$group_name}` rule should be an array.",
			);
		}

		$rules = array();

		foreach ( $object->{$group_name} as $rule_definition ) {
			$rule = RegexReplacementRule::from_rule_definition( $rule_definition );
			if ( null === $rule ) {
				return array(
					'rules' => array(),
					'error' => "The post processing `{$group_name}` rules should be an array of `query` and `replace` strings.",
				);
			}

			$rules[] = $rule;
		}

		return array(
			'rules' => $rules,
			'error' => null,
		);
	}
}
