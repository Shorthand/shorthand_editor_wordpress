<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryContentTransformer {

	/**
	 * Rewrites relative bundle asset paths to their published URL.
	 */
	public function rewrite_story_bundle_paths( string $bundle_url, string $content ): string {
		$content = str_replace( './assets/', $bundle_url . '/assets/', $content );
		$content = str_replace( './static/', $bundle_url . '/static/', $content );

		$rewritten_content = preg_replace( '/.(\/theme-\w+.min.css)/', $bundle_url . '$1', $content );
		return is_string( $rewritten_content ) ? $rewritten_content : $content;
	}

	/**
	 * Applies the configured regex rules to story head and article content.
	 *
	 * @return string[]
	 */
	public function apply_processing_rule_set( string $head, string $article, string $rules_json ): array {
		$rules = $this->get_processing_rules( $rules_json );

		return array(
			'head'    => $this->apply_processing_rules( $head, $rules['head'] ),
			'article' => $this->apply_processing_rules( $article, $rules['body'] ),
		);
	}

	/**
	 * @return object[][]
	 */
	private function get_processing_rules( string $rules_json ): array {
		$result = array(
			'head' => array(),
			'body' => array(),
		);

		if ( '' === $rules_json ) {
			return $result;
		}

		$rules = json_decode( $rules_json );
		if ( ! is_object( $rules ) ) {
			return $result;
		}

		if ( isset( $rules->head ) && is_array( $rules->head ) ) {
			$result['head'] = $rules->head;
		}

		if ( isset( $rules->body ) && is_array( $rules->body ) ) {
			$result['body'] = $rules->body;
		}

		return $result;
	}

	/**
	 * @param object[] $rules
	 */
	private function apply_processing_rules( string $content, array $rules ): string {
		return array_reduce( $rules, array( $this, 'apply_processing_regex_rule' ), $content );
	}

	/**
	 * @param mixed $rule
	 */
	private function apply_processing_regex_rule( string $content, $rule ): string {
		if ( ! is_object( $rule ) || ! isset( $rule->query ) || ! isset( $rule->replace ) ) {
			return $content;
		}

		$updated_content = preg_replace( $rule->query, $rule->replace, $content );
		return is_string( $updated_content ) ? $updated_content : $content;
	}
}
