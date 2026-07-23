<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Admin\Actions\CreateDraftFromStory;
use Shorthand\Core\Loader;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryAttachment;
use Shorthand\Services\StoryAttachmentResolver;
use Shorthand\Services\StoryListCursor;

/**
 * Admin page listing stories in the connected Shorthand site, allowing
 * unattached stories to be linked to a fresh draft WordPress post.
 *
 * Rendering and hook registration in this class are admin-only glue (guarded
 * by `is_admin()` semantics via WordPress's own admin-only hook firing) and
 * are intentionally left untested, matching the existing convention for
 * admin page rendering elsewhere in this codebase (see `Editor`,
 * `GeneralSettingsPage`). The underlying attachment resolution and pagination
 * logic it calls into (`StoryAttachmentResolver`, `StoryListCursor`) are
 * separately unit tested.
 */
class StoriesPage {

	const PAGE_SLUG        = 'shorthand-stories';
	const STORIES_PER_PAGE = 20;

	/**
	 * Capability required to view the page and create draft posts from it.
	 *
	 * `edit_others_posts` restricts access to editors and administrators.
	 */
	const CAPABILITY = 'edit_others_posts';

	/**
	 * Used to fetch the paginated list of stories from the Shorthand API.
	 *
	 * @readonly
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;

	/**
	 * Used to resolve whether each listed story is already attached to a post.
	 *
	 * @readonly
	 * @var \Shorthand\Services\StoryAttachmentResolver
	 */
	private $attachment_resolver;

	/**
	 * Used to detect whether the plugin is connected to a Shorthand workspace.
	 *
	 * @readonly
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	/**
	 * The `tse_story` post type slug, used to build admin URLs.
	 *
	 * @readonly
	 * @var string
	 */
	private $post_type;

	public function __construct( Shorthand $shorthand, StoryAttachmentResolver $attachment_resolver, AuthStateManager $auth_state_manager, string $post_type ) {
		$this->shorthand           = $shorthand;
		$this->attachment_resolver = $attachment_resolver;
		$this->auth_state_manager  = $auth_state_manager;
		$this->post_type           = $post_type;
	}

	/**
	 * The URL of this admin page.
	 *
	 * @param string $post_type The `tse_story` post type slug.
	 */
	public static function get_url( string $post_type ): string {
		return admin_url( 'edit.php?post_type=' . rawurlencode( $post_type ) . '&page=' . self::PAGE_SLUG );
	}

	/**
	 * Register hooks other than the submenu item itself, which must be added
	 * directly from an `admin_menu` callback (see `add_menu_page()` below).
	 *
	 * @param Loader $loader Hook loader to register with.
	 */
	public function define_hooks( Loader $loader ): void {
		$loader->add_action( 'admin_notices', $this, 'render_notices' );
	}

	/**
	 * Add the "Shorthand Stories" submenu page under the story post type's
	 * menu. Must be called directly from an `admin_menu` callback.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . $this->post_type,
			esc_html__( 'Manage Stories', 'the-shorthand-editor' ),
			esc_html__( 'Manage Stories', 'the-shorthand-editor' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the Shorthand Stories admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'the-shorthand-editor' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Shorthand Stories', 'the-shorthand-editor' ) . '</h1>';

		if ( ! $this->auth_state_manager->is_connected() ) {
			echo '<p>' . esc_html__( 'Connect your Shorthand workspace to see and link its stories here.', 'the-shorthand-editor' ) . '</p>';
			echo '</div>';
			return;
		}

		$keyword = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing filter.
		$cursor  = isset( $_GET['shorthand_cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['shorthand_cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination cursor.

		$this->render_search_form( $keyword );

		$args = array( 'limit' => self::STORIES_PER_PAGE );
		if ( '' !== $keyword ) {
			$args['keyword'] = $keyword;
		}
		if ( '' !== $cursor ) {
			$args['cursor'] = $cursor;
		}

		$stories = $this->shorthand->list_stories( $args );

		if ( is_wp_error( $stories ) ) {
			$message = $stories->get_error_message();
			echo '<div class="notice notice-error"><p>' . esc_html( '' !== $message ? $message : __( 'Could not load stories from Shorthand.', 'the-shorthand-editor' ) ) . '</p></div>';
			echo '</div>';
			return;
		}

		$this->render_table( $stories );
		$this->render_pagination( $stories, $keyword );

		echo '</div>';
	}

	/**
	 * Render the keyword search form.
	 *
	 * @param string $keyword Current search keyword, if any.
	 */
	private function render_search_form( string $keyword ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $this->post_type ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<p class="search-box">
				<label class="screen-reader-text" for="shorthand-story-search-input"><?php esc_html_e( 'Search Stories', 'the-shorthand-editor' ); ?></label>
				<input type="search" id="shorthand-story-search-input" name="s" value="<?php echo esc_attr( $keyword ); ?>" />
				<?php submit_button( esc_html__( 'Search Stories', 'the-shorthand-editor' ), '', '', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the stories table.
	 *
	 * @param mixed[] $stories Stories to render, as returned by `Shorthand::list_stories()`.
	 */
	private function render_table( array $stories ): void {
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'the-shorthand-editor' ); ?></th>
					<th><?php esc_html_e( 'Status', 'the-shorthand-editor' ); ?></th>
					<th><?php esc_html_e( 'Last Updated', 'the-shorthand-editor' ); ?></th>
					<th><?php esc_html_e( 'WordPress', 'the-shorthand-editor' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'the-shorthand-editor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $stories ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No stories found.', 'the-shorthand-editor' ); ?></td>
					</tr>
					<?php else : ?>
						<?php foreach ( $stories as $story ) : ?>
							<?php $this->render_row( is_array( $story ) ? $story : array() ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single story row.
	 *
	 * @param mixed[] $story A single story object, as returned by `Shorthand::list_stories()`.
	 */
	private function render_row( array $story ): void {
		$story_id    = isset( $story['id'] ) ? (string) $story['id'] : '';
		$title       = isset( $story['title'] ) && '' !== $story['title'] ? (string) $story['title'] : __( 'Untitled story', 'the-shorthand-editor' );
		$status      = isset( $story['status'] ) ? (string) $story['status'] : '';
		$updated_at  = isset( $story['updatedAt'] ) ? (string) $story['updatedAt'] : '';
		$external_id = ! empty( $story['externalId'] ) ? (string) $story['externalId'] : null;

		$attachment = $this->attachment_resolver->resolve( $story_id, $external_id );

		echo '<tr>';
		echo '<td>' . esc_html( $title ) . '</td>';
		echo '<td>' . esc_html( $status ) . '</td>';
		echo '<td>' . esc_html( $updated_at ) . '</td>';
		echo '<td>' . $this->render_attachment_cell( $attachment ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by render_attachment_cell().
		echo '<td>' . $this->render_actions_cell( $story_id, $attachment ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by render_actions_cell().
		echo '</tr>';
	}

	/**
	 * Render the "WordPress" attachment-state cell for a story. Returns
	 * pre-escaped markup.
	 *
	 * @param StoryAttachment $attachment Resolved attachment state for the story.
	 */
	private function render_attachment_cell( StoryAttachment $attachment ): string {
		$local_post_id = $attachment->get_local_post_id();

		if ( null !== $local_post_id ) {
			$edit_link = get_edit_post_link( $local_post_id, 'raw' );
			if ( $edit_link ) {
				return sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $edit_link ),
					esc_html__( 'Attached', 'the-shorthand-editor' )
				);
			}
			return esc_html__( 'Attached', 'the-shorthand-editor' );
		}

		if ( $attachment->is_attached_elsewhere() ) {
			return '<span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Attached elsewhere (no matching post on this site)', 'the-shorthand-editor' );
		}

		return esc_html__( 'Not attached', 'the-shorthand-editor' );
	}

	/**
	 * Render the actions cell for a story. Returns pre-escaped markup.
	 *
	 * @param string          $story_id   Shorthand story ID.
	 * @param StoryAttachment $attachment Resolved attachment state for the story.
	 */
	private function render_actions_cell( string $story_id, StoryAttachment $attachment ): string {
		if ( $attachment->is_attached() ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s" class="button">%2$s</a>',
			esc_url( CreateDraftFromStory::build_action_url( $story_id ) ),
			esc_html__( 'Create draft post', 'the-shorthand-editor' )
		);
	}

	/**
	 * Render pagination controls: "Next page" when a further page is
	 * available, plus a "First page" link whenever a cursor is currently
	 * applied (no full history stack is kept).
	 *
	 * @param mixed[] $stories Stories on the current page, as returned by `Shorthand::list_stories()`.
	 * @param string  $keyword Current search keyword, if any, preserved across pagination links.
	 */
	private function render_pagination( array $stories, string $keyword ): void {
		$next_cursor = StoryListCursor::next_cursor( $stories, self::STORIES_PER_PAGE );

		$base_args = array(
			'post_type' => $this->post_type,
			'page'      => self::PAGE_SLUG,
		);
		if ( '' !== $keyword ) {
			$base_args['s'] = $keyword;
		}

		$has_cursor = ! empty( $_GET['shorthand_cursor'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination cursor.

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';

		if ( $has_cursor ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $base_args, admin_url( 'edit.php' ) ) ) . '">' . esc_html__( 'First page', 'the-shorthand-editor' ) . '</a>&nbsp;';
		}

		if ( $next_cursor ) {
			$next_args                     = $base_args;
			$next_args['shorthand_cursor'] = $next_cursor;
			echo '<a class="button" href="' . esc_url( add_query_arg( $next_args, admin_url( 'edit.php' ) ) ) . '">' . esc_html__( 'Next page', 'the-shorthand-editor' ) . '</a>';
		}

		echo '</div></div>';
	}

	/**
	 * Render a success/warning/error admin notice after a "Create draft post"
	 * action redirects back to this page.
	 */
	public function render_notices(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || ! isset( $_GET['shorthand_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect outcome, not a state change.
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['shorthand_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $notice ) {
			case 'created':
				$post_id = isset( $_GET['shorthand_post'] ) ? absint( $_GET['shorthand_post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$link    = $post_id ? get_edit_post_link( $post_id, 'raw' ) : null;
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Draft post created from the Shorthand story.', 'the-shorthand-editor' );
				if ( $link ) {
					echo ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Edit draft', 'the-shorthand-editor' ) . '</a>';
				}
				echo '</p></div>';
				break;

			case 'already_attached':
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'This story is already attached to a post on this site, so no draft was created.', 'the-shorthand-editor' ) . '</p></div>';
				break;

			case 'error':
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create a draft post for this story. Please try again.', 'the-shorthand-editor' ) . '</p></div>';
				break;
		}
	}
}
