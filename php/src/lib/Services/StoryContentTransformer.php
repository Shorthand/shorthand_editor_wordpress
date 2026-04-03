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
		$rule_set = RegexRuleSet::from_json( $rules_json );
		if ( null === $rule_set ) {
			return array(
				'head'    => $head,
				'article' => $article,
			);
		}

		return array(
			'head'    => $rule_set->apply_to_head( $head ),
			'article' => $rule_set->apply_to_body( $article ),
		);
	}
}
