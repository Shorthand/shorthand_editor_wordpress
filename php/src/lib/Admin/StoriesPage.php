<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Shorthand;
use Shorthand\Services\StoryAttachmentResolver;

/**
 * Admin page listing stories in the connected Shorthand site, allowing
 * unattached stories to be linked to a fresh draft WordPress post. The
 * table itself is rendered by `StoriesListTable`.
 *
 * Rendering and hook registration in this class are admin-only glue (guarded
 * by `is_admin()` semantics via WordPress's own admin-only hook firing) and
 * are intentionally left untested, matching the existing convention for
 * admin page rendering elsewhere in this codebase (see `Editor`,
 * `GeneralSettingsPage`). The underlying attachment resolution and pagination
 * logic the table calls into (`StoryAttachmentResolver`, `StoryListCursor`)
 * are separately unit tested.
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
			esc_html__( 'Recover Stories', 'the-shorthand-editor' ),
			esc_html__( 'Recover Stories', 'the-shorthand-editor' ),
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
		echo '<h1>' . esc_html__( 'Recover Stories', 'the-shorthand-editor' ) . '</h1>';

		if ( ! $this->auth_state_manager->is_connected() ) {
			echo '<p>' . esc_html__( 'Connect your Shorthand workspace to see and link its stories here.', 'the-shorthand-editor' ) . '</p>';
			echo '</div>';
			return;
		}

		$keyword = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing filter.
		$cursor  = isset( $_GET['shorthand_cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['shorthand_cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination cursor.

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

		$table = new StoriesListTable( $stories, $this->attachment_resolver, $this->post_type, $keyword, '' !== $cursor, self::STORIES_PER_PAGE );
		$table->prepare_items();
		$table->display();

		echo '</div>';
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
