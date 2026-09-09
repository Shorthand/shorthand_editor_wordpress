<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves taxonomies and assigned terms for Shorthand story posts.
 */
class StoryTaxonomyResolver {

	/**
	 * The Shorthand story post type slug.
	 *
	 * @readonly
	 * @var string
	 */
	private $post_type;

	/**
	 * Creates a resolver for a Shorthand story post type.
	 *
	 * @param string $post_type The Shorthand story post type slug.
	 */
	public function __construct( string $post_type ) {
		$this->post_type = $post_type;
	}

	/**
	 * Returns every taxonomy registered for Shorthand stories.
	 *
	 * @return \WP_Taxonomy[] Taxonomy objects keyed by taxonomy slug.
	 */
	public function get_taxonomies(): array {
		return get_object_taxonomies( $this->post_type, 'objects' );
	}

	/**
	 * Returns the taxonomy terms assigned to a Shorthand story.
	 *
	 * Taxonomies with no assigned terms are included with an empty term list.
	 * Taxonomies that WordPress cannot read are omitted.
	 *
	 * @param int $post_id The Shorthand story post ID.
	 * @return array<string, array{taxonomy: \WP_Taxonomy, terms: \WP_Term[]}> Resolved taxonomy data keyed by taxonomy slug.
	 */
	public function resolve( int $post_id ): array {
		$resolved = array();

		foreach ( $this->get_taxonomies() as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy->name );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$resolved[ $taxonomy->name ] = array(
				'taxonomy' => $taxonomy,
				'terms'    => $terms ? $terms : array(),
			);
		}

		return $resolved;
	}
}
