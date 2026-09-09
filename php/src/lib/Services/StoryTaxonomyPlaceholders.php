<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;

/**
 * Replaces story placeholders with assigned taxonomy term names.
 */
class StoryTaxonomyPlaceholders {

	/**
	 * Resolves taxonomy data for a Shorthand story.
	 *
	 * @readonly
	 * @var \Shorthand\Services\StoryTaxonomyResolver
	 */
	private $taxonomy_resolver;

	/**
	 * Creates the taxonomy placeholder integration.
	 *
	 * @param StoryTaxonomyResolver $taxonomy_resolver Resolves story taxonomy data.
	 */
	public function __construct( StoryTaxonomyResolver $taxonomy_resolver ) {
		$this->taxonomy_resolver = $taxonomy_resolver;
	}

	/**
	 * Registers story body processing hooks.
	 */
	public function init(): void {
		$loader = new Loader();
		$loader->add_filter( 'theshed_story_body', $this, 'replace', 10, 2 );
		$loader->register();
	}

	/**
	 * Replaces matching placeholders with assigned taxonomy term names.
	 *
	 * A placeholder may match either the taxonomy slug or its plural display
	 * label, without regard to case. Unknown placeholders are left unchanged
	 * so other integrations can process them.
	 *
	 * @param string $story_body The Shorthand story body HTML.
	 * @param int    $post_id    The Shorthand story post ID.
	 * @return string Story HTML with taxonomy placeholders replaced.
	 */
	public function replace( string $story_body, int $post_id ): string {
		$taxonomy_lookup = array();

		foreach ( $this->taxonomy_resolver->resolve( $post_id ) as $resolved ) {
			$taxonomy = $resolved['taxonomy'];
			$terms    = $resolved['terms'];
			$names    = array();

			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}

			$value = esc_html( implode( ', ', $names ) );

			$taxonomy_lookup[ strtolower( $taxonomy->name ) ]         = $value;
			$taxonomy_lookup[ strtolower( $taxonomy->labels->name ) ] = $value;
		}

		$updated_body = preg_replace_callback(
			'/\{\{\s*([^{}]+?)\s*\}\}/',
			static function ( array $matches ) use ( $taxonomy_lookup ): string {
				$placeholder = strtolower( trim( $matches[1] ) );

				return isset( $taxonomy_lookup[ $placeholder ] )
					? $taxonomy_lookup[ $placeholder ]
					: $matches[0];
			},
			$story_body
		);

		return is_string( $updated_body ) ? $updated_body : $story_body;
	}
}
