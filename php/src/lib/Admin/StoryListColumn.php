<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Services\PostAPI;

/**
 * Adds a "Story" column to the stories list, and the intent field to Quick Edit and bulk edit.
 *
 * Core fires `quick_edit_custom_box` and `bulk_edit_custom_box` only for
 * columns it does not own (`WP_Posts_List_Table::inline_edit()`), hidden
 * ones included. The column gives those hooks a place to print the field.
 */
class StoryListColumn {

	/**
	 * Column key in the list table.
	 */
	const COLUMN = 'shorthand_story';

	/**
	 * Reads the story's update state.
	 *
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;

	/**
	 * Story post type.
	 *
	 * @var string
	 */
	private $post_type;

	public function __construct( PostAPI $post_api, string $post_type ) {
		$this->post_api  = $post_api;
		$this->post_type = $post_type;
	}

	/**
	 * Registers the column and the custom box hooks.
	 *
	 * @param Loader $loader Collects the hooks.
	 */
	public function init( Loader $loader ): void {
		$loader->add_filter( "manage_{$this->post_type}_posts_columns", $this, 'add_column', 10, 1 );
		$loader->add_action( "manage_{$this->post_type}_posts_custom_column", $this, 'render_column', 10, 2 );
		$loader->add_action( 'quick_edit_custom_box', $this, 'render_intent_field', 10, 2 );
		$loader->add_action( 'bulk_edit_custom_box', $this, 'render_intent_field', 10, 2 );
	}

	/**
	 * Adds the column before the date, or last when there is no date column.
	 *
	 * @param array<string, string> $columns List table columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$label  = __( 'Story', 'the-shorthand-editor' );
		$result = array();
		foreach ( $columns as $key => $title ) {
			if ( 'date' === $key ) {
				$result[ self::COLUMN ] = $label;
			}
			$result[ $key ] = $title;
		}
		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = $label;
		}
		return $result;
	}

	/**
	 * Prints the story's update state in its row.
	 *
	 * @param string $column  Column being printed.
	 * @param int    $post_id Post of the row.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$state = $this->describe( (int) $post_id );
		if ( '' === $state ) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}

		echo esc_html( $state );
	}

	/**
	 * The update state shown in the column, or an empty string for a story never fetched.
	 *
	 * @param int $post_id Post of the row.
	 * @return string
	 */
	public function describe( int $post_id ): string {
		if ( null !== $this->post_api->get_story_update_error( $post_id ) ) {
			return __( 'Update failed', 'the-shorthand-editor' );
		}

		$progress = $this->post_api->get_story_update_progress( $post_id );
		if ( null !== $progress ) {
			/* translators: %d: Percent of the story download done. */
			return sprintf( __( 'Updating %d%%', 'the-shorthand-editor' ), (int) round( $progress->to_array()['percent'] ) );
		}

		if ( null !== $this->post_api->get_post_story_version( $post_id ) ) {
			return __( 'Fetched', 'the-shorthand-editor' );
		}

		return '';
	}

	/**
	 * Prints the intent field into the Quick Edit or bulk edit template.
	 *
	 * The template is cloned for each row, so the input has no `id`.
	 *
	 * @param string $column    Column whose box is printed.
	 * @param string $post_type Post type of the list.
	 */
	public function render_intent_field( $column, $post_type ): void {
		if ( self::COLUMN !== $column || $this->post_type !== $post_type ) {
			return;
		}

		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( StorySaveIntent::FIELD ),
			esc_attr( wp_create_nonce( StorySaveIntent::LIST_ACTION ) )
		);
	}
}
