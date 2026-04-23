<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RegexReplacementRule {

	/**
	 * @var string
	 */
	private $query;

	/**
	 * @var string
	 */
	private $replace;

	public function __construct( string $query, string $replace ) {
		$this->query   = $query;
		$this->replace = $replace;
	}

	/**
	 * @param mixed $rule
	 */
	public static function from_rule_definition( $rule ): ?self {
		if (
			! is_object( $rule ) ||
			! isset( $rule->query ) ||
			! is_string( $rule->query ) ||
			! isset( $rule->replace ) ||
			! is_string( $rule->replace )
		) {
			return null;
		}

		if ( @preg_match( $rule->query, '' ) === false ) {
			return null;
		}

		return new self( $rule->query, $rule->replace );
	}

	public function apply( string $content ): string {
		$updated_content = preg_replace( $this->query, $this->replace, $content );
		return is_string( $updated_content ) ? $updated_content : $content;
	}
}
