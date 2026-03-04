<?php

namespace Shorthand\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Version;
use Shorthand\Core\Loader;

class PostType {

	/**
	 * @readonly
	 * @var string
	 */
	public $post_type;
	/**
	 * @readonly
	 * @var string
	 */
	public $permalink_slug;
	/**
	 * @readonly
	 * @var \Shorthand\Services\Options
	 */
	public $options;
	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	public function __construct( string $permalink_slug, Version $version ) {
		$this->version        = $version;
		$this->post_type      = 'shorthand_story';
		$this->permalink_slug = $permalink_slug;
	}

	public function init() {
		$loader = new Loader();
		$loader->add_action( 'init', $this, 'register_post_type' );
		$loader->register();
	}

	public function register_post_type() {
		register_post_type(
			$this->post_type,
			array(
				'labels'             => array(
					'name'               => __( 'Stories', 'the-shorthand-editor' ),
					'singular_name'      => __( 'Story', 'the-shorthand-editor' ),
					'add_new'            => __( 'Add Story', 'the-shorthand-editor' ),
					'add_new_item'       => __( 'Add Story', 'the-shorthand-editor' ),
					'new_item'           => __( 'New Story', 'the-shorthand-editor' ),
					'edit_item'          => __( 'Update Story', 'the-shorthand-editor' ),
					'view_item'          => __( 'View Story', 'the-shorthand-editor' ),
					'not_found'          => __( 'No stories found', 'the-shorthand-editor' ),
					'not_found_in_trash' => __( 'No stories found in trash', 'the-shorthand-editor' ),
					'all_items'          => __( 'All Stories', 'the-shorthand-editor' ),
				),
				'show_in_rest'       => true,
				'show_ui'            => true,
				'rest_base'          => 'stories',
				'rest_namespace'     => 'shorthand/v1',
				'publicly_queryable' => true,
				'public'             => true,
				'has_archive'        => true,
				'menu_position'      => 4,
				'supports'           => array( 'title', 'thumbnail', 'excerpt', 'page-attributes', 'author', 'custom-fields' ),
				'menu_icon'          => $this->version->get_plugin_url( 'assets/admin/images/icon.png' ),
				'rewrite'            => array(
					'slug' => $this->permalink_slug,
				),
				'taxonomies'         => array( 'category', 'post_tag' ),
			)
		);

		register_taxonomy_for_object_type( 'category', $this->post_type );
		register_taxonomy_for_object_type( 'post_tag', $this->post_type );

		register_post_meta(
			'shorthand_story',
			'story_id',
			array(
				'single'            => true,
				'type'              => 'string',
				'description'       => __( 'Shorthand story ID', 'the-shorthand-editor' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_post_meta(
			'shorthand_story',
			'story_version',
			array(
				'single'            => true,
				'type'              => 'number',
				'description'       => __( 'Shorthand story version', 'the-shorthand-editor' ),
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_story_version' ),
			)
		);

		register_post_meta(
			'shorthand_story',
			'story_update_nonce',
			array(
				'single'            => true,
				'type'              => 'string',
				'description'       => __( 'An internal nonce for Shorthand story requests', 'the-shorthand-editor' ),
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_post_meta(
			'shorthand_story',
			'story_update_state',
			array(
				'single'       => true,
				'type'         => 'object',
				'description'  => __( 'The current state of the Shorthand story update process', 'the-shorthand-editor' ),
				'show_in_rest' => false,
			)
		);

		register_post_meta(
			'shorthand_story',
			'story_head',
			array(
				'single'       => true,
				'type'         => 'string',
				'description'  => __( 'Shorthand story article', 'the-shorthand-editor' ),
				'show_in_rest' => false,
			)
		);

		register_post_meta(
			'shorthand_story',
			'story_body',
			array(
				'single'       => true,
				'type'         => 'string',
				'description'  => __( 'Shorthand story head material', 'the-shorthand-editor' ),
				'show_in_rest' => false,
			)
		);

		$loader = new Loader();

		$loader->add_filter( 'is_protected_meta', $this, 'is_protected_meta', 10, 3 );

		// $loader->add_action('pre_get_posts', $this, 'pre_get_posts_update_taxonomies');

		$loader->register();
	}

	public function sanitize_story_version( $version ): ?int {
		if ( is_int( $version ) ) {
			return absint( $version );
		}

		return null;
	}

	public function is_protected_meta( $prot, $meta_key, $meta_type ) {
		$protected_meta_keys = array( 'story_id', 'story_body', 'story_head', 'story_version', 'story_update_nonce', 'story_update_state' );
		if ( 'post' === $meta_type && in_array( $meta_key, $protected_meta_keys, true ) ) {
			return true;
		}
		return $prot;
	}

	public function pre_get_posts_update_taxonomies( \WP_Query $query ) {
		if ( ! is_admin() && $query->is_main_query() && ( is_home() || is_archive() || is_search() ) ) {
			$post_types = $query->get( 'post_type' );
			if ( empty( $post_types ) ) {
				// default to 'post' if no post type is set
				$post_types = array( 'post', $this->post_type );
			} elseif ( is_string( $post_types ) ) {
				$post_types = array( $post_types );
				if ( ! in_array( 'post', $post_types, true ) ) {
					$post_types[] = 'post';
				}
				if ( ! in_array( $this->post_type, $post_types, true ) ) {
					$post_types[] = $this->post_type;
				}
			} elseif ( is_array( $post_types ) ) {
				if ( ! in_array( 'post', $post_types, true ) ) {
					$post_types[] = 'post';
				}
				if ( ! in_array( $this->post_type, $post_types, true ) ) {
					$post_types[] = $this->post_type;
				}
			}
			$query->set( 'post_type', $post_types );
		}
	}
}
