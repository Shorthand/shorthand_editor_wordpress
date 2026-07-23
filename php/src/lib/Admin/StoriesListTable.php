<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Admin\Actions\CreateDraftFromStory;
use Shorthand\Services\StoryAttachment;
use Shorthand\Services\StoryAttachmentResolver;
use Shorthand\Services\StoryListCursor;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table of stories in the connected Shorthand site, rendered on the
 * "Recover Stories" admin page (see `StoriesPage`).
 *
 * Pagination is cursor-based (the Shorthand API does not expose a total
 * count or numbered pages), so the core numbered pagination is replaced by
 * "First page" / "Next page" links.
 *
 * Like `StoriesPage`, this is admin-only rendering glue and is intentionally
 * left untested; the attachment resolution and pagination logic it calls
 * into (`StoryAttachmentResolver`, `StoryListCursor`) are separately unit
 * tested.
 */
class StoriesListTable extends \WP_List_Table {

	/**
	 * Stories on the current page, as returned by `Shorthand::list_stories()`.
	 *
	 * @readonly
	 * @var mixed[]
	 */
	private $stories;

	/**
	 * Used to resolve whether each listed story is already attached to a post.
	 *
	 * @readonly
	 * @var \Shorthand\Services\StoryAttachmentResolver
	 */
	private $attachment_resolver;

	/**
	 * The `tse_story` post type slug, used to build pagination URLs.
	 *
	 * @readonly
	 * @var string
	 */
	private $post_type;

	/**
	 * Current search keyword, if any, preserved across pagination links.
	 *
	 * @readonly
	 * @var string
	 */
	private $keyword;

	/**
	 * Whether a pagination cursor is currently applied, in which case a
	 * "First page" link is offered (no full history stack is kept).
	 *
	 * @readonly
	 * @var bool
	 */
	private $has_cursor;

	/**
	 * Page size the stories were fetched with, used to detect a further page.
	 *
	 * @readonly
	 * @var int
	 */
	private $per_page;

	/**
	 * Resolved attachment states keyed by story ID, so the title and
	 * attachment columns of a row share a single resolver lookup.
	 *
	 * @var \Shorthand\Services\StoryAttachment[]
	 */
	private $attachment_cache = array();

	/**
	 * Set up the list table for a fetched page of stories.
	 *
	 * @param mixed[]                 $stories             Stories on the current page, as returned by `Shorthand::list_stories()`.
	 * @param StoryAttachmentResolver $attachment_resolver Resolves each story's attachment state.
	 * @param string                  $post_type           The `tse_story` post type slug.
	 * @param string                  $keyword             Current search keyword, if any.
	 * @param bool                    $has_cursor          Whether a pagination cursor is currently applied.
	 * @param int                     $per_page            Page size the stories were fetched with.
	 */
	public function __construct( array $stories, StoryAttachmentResolver $attachment_resolver, string $post_type, string $keyword, bool $has_cursor, int $per_page ) {
		parent::__construct(
			array(
				'singular' => 'shorthand-story',
				'plural'   => 'shorthand-stories',
				'ajax'     => false,
			)
		);

		$this->stories             = $stories;
		$this->attachment_resolver = $attachment_resolver;
		$this->post_type           = $post_type;
		$this->keyword             = $keyword;
		$this->has_cursor          = $has_cursor;
		$this->per_page            = $per_page;
	}

	/**
	 * The table's columns.
	 *
	 * @return string[] Column labels keyed by column slug.
	 */
	public function get_columns() {
		return array(
			'title'      => __( 'Title', 'the-shorthand-editor' ),
			'attachment' => __( 'WordPress', 'the-shorthand-editor' ),
			'date'       => __( 'Date', 'the-shorthand-editor' ),
		);
	}

	/**
	 * Load the fetched stories into the table.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
		$this->items           = array_values( array_filter( $this->stories, 'is_array' ) );
	}

	/**
	 * Message shown when there are no stories to list.
	 */
	public function no_items() {
		esc_html_e( 'No stories found.', 'the-shorthand-editor' );
	}

	/**
	 * Render the title column: the title and status in bold on one line,
	 * separated by an em dash, with the title linking to the edit-post page
	 * when the story is attached to a local post, and row-action links
	 * underneath. Returns pre-escaped markup.
	 *
	 * @param mixed[] $story A single story, as returned by `Shorthand::list_stories()`.
	 * @return string
	 */
	protected function column_title( $story ) {
		$story_id = isset( $story['id'] ) ? (string) $story['id'] : '';
		$title    = isset( $story['title'] ) && '' !== $story['title'] ? (string) $story['title'] : __( 'Untitled story', 'the-shorthand-editor' );
		$status   = isset( $story['status'] ) ? (string) $story['status'] : '';

		$attachment    = $this->resolve_attachment( $story );
		$local_post_id = $attachment->get_local_post_id();
		$edit_link     = null !== $local_post_id ? get_edit_post_link( $local_post_id, 'raw' ) : null;

		if ( $edit_link ) {
			$markup = sprintf(
				'<a class="row-title" href="%1$s">%2$s</a>',
				esc_url( $edit_link ),
				esc_html( $title )
			);
		} else {
			$markup = esc_html( $title );
		}

		if ( '' !== $status ) {
			$markup .= ' &mdash; <span class="post-state">' . esc_html( ucfirst( $status ) ) . '</span>';
		}

		return '<strong>' . $markup . '</strong>' . $this->row_actions( $this->get_story_row_actions( $story_id, $edit_link, $attachment ) );
	}

	/**
	 * Render the WordPress attachment column: a warning when the story is not
	 * attached to a post on this site, empty otherwise. Returns pre-escaped
	 * markup.
	 *
	 * @param mixed[] $story A single story, as returned by `Shorthand::list_stories()`.
	 * @return string
	 */
	protected function column_attachment( $story ) {
		$attachment = $this->resolve_attachment( $story );

		if ( $attachment->is_attached() ) {
			return esc_html__( 'Attached', 'the-shorthand-editor' );
		}

		if ( $attachment->is_attached_elsewhere() ) {
			return '<span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Warning:', 'the-shorthand-editor' ) . '</span> ' . esc_html__( 'Attached (linked WordPress post not found)', 'the-shorthand-editor' );
		}

		return esc_html__( 'Unattached', 'the-shorthand-editor' );
	}

	/**
	 * Render the date column: "Last Updated", then the story's `updatedAt`
	 * on a new line in the site's timezone, e.g. "2026/07/23 at 2:45 PM".
	 * Returns pre-escaped markup.
	 *
	 * @param mixed[] $story A single story, as returned by `Shorthand::list_stories()`.
	 * @return string
	 */
	protected function column_date( $story ) {
		$updated_at = isset( $story['updatedAt'] ) ? (string) $story['updatedAt'] : '';
		$timestamp  = '' !== $updated_at ? strtotime( $updated_at ) : false;

		if ( false === $timestamp ) {
			return esc_html( $updated_at );
		}

		return esc_html__( 'Last Updated', 'the-shorthand-editor' ) . '<br />' .
			esc_html( wp_date( __( 'Y/m/d \a\t g:i A', 'the-shorthand-editor' ), $timestamp ) );
	}

	/**
	 * Fallback for columns without a dedicated renderer; all columns here
	 * have one, so this renders nothing.
	 *
	 * @param mixed[] $item        A single story.
	 * @param string  $column_name The column being rendered.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Resolve a story's attachment state, memoized per story so the title
	 * and attachment columns of a row share a single lookup.
	 *
	 * @param mixed[] $story A single story, as returned by `Shorthand::list_stories()`.
	 */
	private function resolve_attachment( array $story ): StoryAttachment {
		$story_id    = isset( $story['id'] ) ? (string) $story['id'] : '';
		$external_id = ! empty( $story['externalId'] ) ? (string) $story['externalId'] : null;

		if ( ! isset( $this->attachment_cache[ $story_id ] ) ) {
			$this->attachment_cache[ $story_id ] = $this->attachment_resolver->resolve( $story_id, $external_id );
		}

		return $this->attachment_cache[ $story_id ];
	}

	/**
	 * The row-action links shown under a story's title: "Edit post" when the
	 * story is attached to a local post, "Create draft post" when it is not
	 * attached anywhere.
	 *
	 * @param string          $story_id   Shorthand story ID.
	 * @param string|null     $edit_link  Edit-post URL for the attached local post, if any.
	 * @param StoryAttachment $attachment Resolved attachment state for the story.
	 * @return string[] Pre-escaped action links keyed by action slug.
	 */
	private function get_story_row_actions( string $story_id, ?string $edit_link, StoryAttachment $attachment ): array {
		$actions = array();

		if ( $edit_link ) {
			$actions['edit'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_link ),
				esc_html__( 'Edit post', 'the-shorthand-editor' )
			);
		} elseif ( ! $attachment->is_attached() ) {
			$actions['create-draft'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( CreateDraftFromStory::build_action_url( $story_id ) ),
				esc_html__( 'Create draft post', 'the-shorthand-editor' )
			);
		}

		return $actions;
	}

	/**
	 * Render cursor-based pagination using the core pager's arrow controls.
	 *
	 * The Shorthand API exposes no total count and no backwards cursor, so
	 * only "first page" and "next page" arrows are shown, each disabled when
	 * unavailable, exactly as the core pager renders its unavailable arrows.
	 * The previous/last arrows, item count, and current-page input are
	 * omitted for the same reason.
	 *
	 * @param string $which Which tablenav is being rendered: 'top' or 'bottom'.
	 */
	protected function pagination( $which ) {
		$next_cursor = StoryListCursor::next_cursor( $this->stories, $this->per_page );

		if ( ! $this->has_cursor && ! $next_cursor ) {
			return;
		}

		$base_args = array(
			'post_type' => $this->post_type,
			'page'      => StoriesPage::PAGE_SLUG,
		);
		if ( '' !== $this->keyword ) {
			$base_args['s'] = $this->keyword;
		}

		$disabled = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%s</span>';

		echo '<div class="tablenav-pages"><span class="pagination-links">';

		if ( $this->has_cursor ) {
			echo '<a class="first-page button" href="' . esc_url( add_query_arg( $base_args, admin_url( 'edit.php' ) ) ) . '">'
				. '<span class="screen-reader-text">' . esc_html__( 'First page', 'the-shorthand-editor' ) . '</span>'
				. '<span aria-hidden="true">&laquo;</span></a> ';
		} else {
			printf( $disabled . ' ', '&laquo;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		}

		if ( $next_cursor ) {
			$next_args                     = $base_args;
			$next_args['shorthand_cursor'] = $next_cursor;
			echo '<a class="next-page button" href="' . esc_url( add_query_arg( $next_args, admin_url( 'edit.php' ) ) ) . '">'
				. '<span class="screen-reader-text">' . esc_html__( 'Next page', 'the-shorthand-editor' ) . '</span>'
				. '<span aria-hidden="true">&rsaquo;</span></a>';
		} else {
			printf( $disabled, '&rsaquo;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		}

		echo '</span></div>';
	}
}
