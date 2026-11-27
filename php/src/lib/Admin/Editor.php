<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Core\Version;
use Shorthand\Services\AuthStateManager;
use Shorthand\Services\Options;
use Shorthand\Services\Shorthand;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\StoryCover;
use Shorthand\Services\StoryPullWatch;
use Shorthand\Services\StorySyncState;
use Shorthand\Services\StoryTitleSync;
use Shorthand\Admin\Actions\PostPreview;
use Shorthand\Admin\Actions\EditWithShorthand;
use Shorthand\Services\Cron;
use WP_Post;
use WP_Error;

class Editor {

	/**
	 * @var string
	 */
	private $post_type;
	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;
	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;
	/**
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;
	/**
	 * @var \Shorthand\Admin\Actions\PostPreview
	 */
	private $post_preview;
	/**
	 * @var \Shorthand\Admin\Actions\EditWithShorthand
	 */
	private $edit_with_shorthand;
	/**
	 * @var \Shorthand\Services\Cron
	 */
	private $cron;

	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;

	/**
	 * @var \Shorthand\Services\Permissions
	 */
	private $permissions;

	/**
	 * @var \Shorthand\Services\StoryCover
	 */
	private $story_cover;

	/**
	 * @var \Shorthand\Services\StoryTitleSync
	 */
	private $title_sync;

	/**
	 * Fails a stalled pull before the poll or a save reads its state.
	 *
	 * @var \Shorthand\Services\StoryPullWatch
	 */
	private $pull_watch;

	/**
	 * Tells a save the editor made from a save by anything else.
	 *
	 * @var \Shorthand\Admin\StorySaveIntent
	 */
	private $save_intent;

	public function __construct(
		Options $options,
		Shorthand $shorthand,
		Cron $cron,
		Version $version,
		PostAPI $post_api,
		PostPreview $post_preview,
		EditWithShorthand $edit_with_shorthand,
		string $post_type,
		AuthStateManager $auth_state_manager,
		Permissions $permissions,
		StoryCover $story_cover,
		StoryTitleSync $title_sync,
		StoryPullWatch $pull_watch,
		StorySaveIntent $save_intent
	) {
		$this->post_type           = $post_type;
		$this->options             = $options;
		$this->shorthand           = $shorthand;
		$this->cron                = $cron;
		$this->version             = $version;
		$this->post_api            = $post_api;
		$this->post_preview        = $post_preview;
		$this->edit_with_shorthand = $edit_with_shorthand;
		$this->auth_state_manager  = $auth_state_manager;
		$this->permissions         = $permissions;
		$this->story_cover         = $story_cover;
		$this->title_sync          = $title_sync;
		$this->pull_watch          = $pull_watch;
		$this->save_intent         = $save_intent;
	}

	public function init( Loader $loader ) {
		$loader->add_filter( 'wp_insert_post_data', $this, 'wp_insert_post_data', 10, 4 );

		$loader->add_action( "save_post_{$this->post_type}", $this, 'save_shorthand_story', 10, 2 );

		$loader->add_action( 'edit_form_after_title', $this, 'edit_form_after_title', 10, 1 );

		$loader->add_filter( 'post_row_actions', $this, 'row_action_edit_with_shorthand', 10, 2 );

		$loader->add_filter( 'admin_enqueue_scripts', $this, 'admin_enqueue_scripts', 10, 1 );

		$loader->add_action( 'load-post.php', $this, 'load_edit_screen', 10, 0 );

		$loader->add_action( 'wp_ajax_shorthand_get_story_state', $this, 'ajax_get_story_state', 10, 1 );

		$loader->add_filter( 'admin_post_thumbnail_html', $this, 'admin_post_thumbnail_html', 10, 3 );

		$loader->add_action( 'wp_ajax_shorthand_get_story_cover', $this, 'ajax_get_story_cover', 10, 1 );

		$loader->add_action( 'wp_ajax_shorthand_import_story_cover', $this, 'ajax_import_story_cover', 10, 1 );

		$loader->add_action( 'before_delete_post', $this, 'before_delete_post', 10, 2 );

		$loader->add_filter( 'wp_refresh_nonces', $this, 'refresh_nonces', 11, 2 );
	}

	public function get_story_id( WP_Post $post ): string {
		return get_post_meta( $post->ID, 'story_id', true );
	}

	public function before_delete_post( int $post_id, WP_Post $post ): void {
		if ( $post->post_type !== $this->post_type ) {
			return;
		}

		$story_id = get_post_meta( $post_id, 'story_id', true );
		if ( empty( $story_id ) ) {
			return;
		}

		$this->post_api->delete_story_bundle( $post_id, $story_id );
	}

	/**
	 * Saves WordPress properties in every auth state. A lost connection
	 * blocks one thing only: fetching a new story bundle from Shorthand.
	 *
	 * Only a save the editor made, from the edit screen, Quick Edit or bulk
	 * edit, may fetch the story, check Shorthand for a new version or push
	 * the title. Any other save follows `save_without_intent()`.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @param array<string, mixed> $unsanitized_postarr
	 * @return array<string, mixed>
	 */
	public function wp_insert_post_data( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		if ( ! $this->is_story_type( $data['post_type'] ) ) {
			return $data;
		}

		/*
		 * A story writing its own text back is not an editor publishing it.
		 * Without this the write would queue another pull, and each pull would
		 * queue the next.
		 */
		if ( $this->post_api->is_storing_text() ) {
			return $data;
		}

		$post_id      = (int) $postarr['ID'];
		$prior_status = get_post_status( $post_id );

		if ( ! $this->save_intent->claim( $post_id ) ) {
			if ( $this->save_intent->is_autosave( $post_id ) ) {
				$this->sync_title( $post_id, stripslashes( $data['post_title'] ) );
			}
			return $this->save_without_intent( $post_id, $data, $prior_status );
		}

		/* A notice explains the last save from the editor; this save replaces it. */
		$this->post_api->set_story_update_notice( $post_id );

		$this->sync_title( $post_id, stripslashes( $data['post_title'] ) );

		if ( ! $this->is_publishing_status( $data['post_status'] ) ) {
			return $data;
		}

		$publishing = ! $this->is_publishing_status( $prior_status );

		if ( ! $this->auth_state_manager->is_connected() ) {
			if ( $publishing && ! $this->has_story_content( $post_id ) ) {
				$this->post_api->set_story_update_error(
					$post_id,
					new WP_Error( 'auth', __( 'Cannot fetch the story content from Shorthand: the connection is not active. Reconnect the workspace, then publish again.', 'the-shorthand-editor' ) )
				);
				$data['post_status'] = PostAPI::get_restore_status( $prior_status );
			}
			return $data;
		}

		if ( ! $publishing && ! $this->needs_pull( $post_id ) ) {
			return $data;
		}

		$this->post_api->set_story_update_progress( $post_id );
		$this->post_api->set_story_update_error( $post_id );

		$result = $this->cron->schedule_pull_story( $post_id );
		if ( is_wp_error( $result ) || ! $result ) {
			$error = is_wp_error( $result ) ? $result : new WP_Error( 'cron', __( 'The story download could not be scheduled.', 'the-shorthand-editor' ) );
			$this->post_api->set_story_update_error( $post_id, $error );
			$data['post_status'] = PostAPI::get_restore_status( $prior_status );
		}

		return $data;
	}

	/**
	 * Applies the rules for a save the editor did not make.
	 *
	 * Another plugin, core outside the edit screen, or an edit screen whose
	 * nonce expired saved the post. Nothing here calls Shorthand or pushes
	 * the title, and a pull in flight is left alone. A publish of a post with
	 * story content goes ahead without a pull. A publish of a post with none
	 * keeps the prior status, as only the editor may fetch the story.
	 *
	 * @param int                  $post_id      Post being saved.
	 * @param array<string, mixed> $data         Post fields about to be saved.
	 * @param string|false         $prior_status Status before this save.
	 * @return array<string, mixed>
	 */
	private function save_without_intent( int $post_id, array $data, $prior_status ): array {
		if ( ! $this->is_publishing_status( $data['post_status'] ) ) {
			return $data;
		}

		if ( $this->save_intent->expired( $post_id ) ) {
			$this->post_api->set_story_update_notice(
				$post_id,
				new WP_Error( 'pretty', __( 'The page had expired, so the story was not fetched. Reload and click Update.', 'the-shorthand-editor' ) )
			);
		}

		if ( $this->is_publishing_status( $prior_status ) || $this->has_story_content( $post_id ) ) {
			return $data;
		}

		$data['post_status'] = PostAPI::get_restore_status( $prior_status );

		/* A pull in flight owns the error; it reports its own outcome. */
		if ( null === $this->post_api->get_story_update_progress( $post_id ) ) {
			$this->post_api->set_story_update_error(
				$post_id,
				new WP_Error( 'pretty', __( 'Open the story and click Publish to fetch it from Shorthand.', 'the-shorthand-editor' ) )
			);
		}

		return $data;
	}

	/**
	 * The WordPress title always saves; Shorthand receives it when it can.
	 */
	private function sync_title( int $post_id, string $title ): void {
		if ( ! $post_id ) {
			return;
		}

		$story_id = get_post_meta( $post_id, 'story_id', true );
		if ( ! $story_id ) {
			return;
		}

		$changed = get_post_field( 'post_title', $post_id, 'raw' ) !== $title;
		if ( ! $changed && null === $this->title_sync->get_pending( $post_id ) ) {
			return;
		}

		$this->title_sync->push( $post_id, (string) $story_id, $title );
	}

	private function has_story_content( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, 'story_body', true );
	}

	/**
	 * A published post pulls again only when it has no bundle, the last
	 * pull failed, or Shorthand holds a different content version. A pull
	 * still in flight is left to finish; a stalled one fails here first.
	 *
	 * A failed version check starts no pull. It stores a notice instead, so
	 * a short Shorthand outage does not turn a save into a full download.
	 */
	private function needs_pull( int $post_id ): bool {
		if ( null !== $this->post_api->get_story_update_error( $post_id ) ) {
			return true;
		}

		$watch = $this->pull_watch->check( $post_id, true );
		if ( StoryPullWatch::RUNNING === $watch || StoryPullWatch::RESUMED === $watch ) {
			return false;
		}

		if ( StoryPullWatch::DEAD === $watch ) {
			return true;
		}

		$live_version = $this->post_api->get_post_story_version( $post_id );
		if ( null === $live_version ) {
			return true;
		}

		$story_id = get_post_meta( $post_id, 'story_id', true );
		if ( ! $story_id ) {
			return true;
		}

		$remote_version = $this->shorthand->get_story_version( (string) $story_id );
		if ( is_wp_error( $remote_version ) ) {
			$notice = new WP_Error( 'pretty', __( 'Could not check Shorthand for updates.', 'the-shorthand-editor' ) );
			$notice->merge_from( $remote_version );
			$this->post_api->set_story_update_notice( $post_id, $notice );
			return false;
		}

		return $remote_version !== $live_version;
	}

	/**
	 * Clears the recorded story version when a post leaves a publishing status.
	 *
	 * Publishing itself is scheduled in `wp_insert_post_data()`, which runs
	 * before the post is saved.
	 *
	 * @param int      $post_id Post being saved.
	 * @param \WP_Post $post    Post being saved.
	 */
	public function save_shorthand_story( $post_id, $post ) {
		if ( $this->post_api->is_storing_text() ) {
			return;
		}

		if ( 'publish' !== $post->post_status && 'future' !== $post->post_status ) {
			$this->post_api->set_post_story_version( $post_id, null );
		}
	}

	/**
	 * @param object|mixed[]|string|null $data
	 */
	private function is_story_type( $data = null ): bool {
		if ( is_object( $data ) ) {
			return $data->post_type === $this->post_type;
		} elseif ( is_array( $data ) ) {
			return $data['post_type'] === $this->post_type;
		} elseif ( is_string( $data ) ) {
			return $data === $this->post_type;
		} elseif ( null === $data ) {
			return get_post_type() === $this->post_type;
		} else {
			return false;
		}
	}

	/**
	 * @param object|mixed[]|string|null $data
	 */
	private function is_publishing_status( $data = null ): bool {
		$status = $data;
		if ( is_object( $data ) ) {
			$status = $data->post_status;
		} elseif ( is_array( $data ) ) {
			$status = $data['post_status'];
		} elseif ( is_string( $data ) ) {
			$status = $data;
		} elseif ( null === $data ) {
			$status = get_post_status();
		} else {
			return false;
		}
		return PostAPI::is_publishing_status( $status );
	}

	public function edit_form_after_title( $post ) {
		if ( $post->post_type !== $this->post_type ) {
			return;
		}

		wp_nonce_field( StorySaveIntent::edit_action( $post->ID ), StorySaveIntent::FIELD, false );

		$this->render_story_preview( $post );
	}

	/**
	 * Renews the intent field with core's post nonces.
	 *
	 * An edit screen left open past the nonce lifetime would otherwise save
	 * with no intent. `post.js` writes each `replace` value into the element
	 * with that id. Core adds the list only for a user who can edit the post.
	 *
	 * @param array<string, mixed> $response Heartbeat response.
	 * @param array<string, mixed> $data     Heartbeat data.
	 * @return array<string, mixed>
	 */
	public function refresh_nonces( $response, $data ) {
		if ( ! isset( $response['wp-refresh-post-nonces']['replace'] ) || ! is_array( $response['wp-refresh-post-nonces']['replace'] ) ) {
			return $response;
		}

		$post_id = isset( $data['wp-refresh-post-nonces']['post_id'] ) ? absint( $data['wp-refresh-post-nonces']['post_id'] ) : 0;
		if ( ! $post_id || get_post_type( $post_id ) !== $this->post_type ) {
			return $response;
		}

		$response['wp-refresh-post-nonces']['replace'][ StorySaveIntent::FIELD ] = wp_create_nonce( StorySaveIntent::edit_action( $post_id ) );

		return $response;
	}

	public function render_story_preview( $post ) {
		$preview_url = $this->post_preview->get_preview_url( $post );
		include $this->version->get_plugin_path( 'assets/admin/partials/preview-panel.php' );
	}

	public function row_action_edit_with_shorthand( array $actions, WP_Post $post ) {
		if ( $post->post_type !== $this->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		if ( ! $this->auth_state_manager->is_connected() ) {
			return $actions;
		}

		$story_id            = $this->get_story_id( $post );
		$shorthand_story_url = $this->edit_with_shorthand->get_url( $post, $story_id );

		ob_start();
		include $this->version->get_plugin_path( 'assets/admin/partials/row-action-edit.php' );
		$actions['edit_with_shorthand'] = ob_get_clean();

		return $actions;
	}

	private function get_story_edit_url( $post ) {
		$base_url = $this->options->get_dashboard_url();

		$id = $this->get_story_id( $post );
		if ( $id ) {
			return $base_url . '/stories/' . $id;
		}

		return null;
	}


	public function admin_enqueue_scripts( $hook ) {
		if (
			get_post_type() !== $this->post_type ||
			( 'post.php' !== $hook && 'post-new.php' !== $hook )
		) {
			return;
		}

		$post     = get_post();
		$story_id = $this->get_story_id( $post );

		$is_connected = $this->auth_state_manager->is_connected();
		$auth_state   = $this->auth_state_manager->get_state();

		// Only provide an edit URL when the plugin is in a connected state.
		$edit_url    = $is_connected ? $this->edit_with_shorthand->get_url( $post, $story_id ) : null;
		$story_state = $this->get_post_story_state( $post->ID );

		wp_enqueue_style( 'theshed-post-components-style', $this->version->get_plugin_url( 'public/scripts/post.min.css' ), array(), $this->version->get_plugin_version() );
		wp_add_inline_style(
			'theshed-post-components-style',
			'#theshed-toolbar { width: 100%; }'
			. ' .theshed-cover-panel [hidden] { display: none !important; }'
			. ' .theshed-cover-panel__tabs { display: flex; gap: 16px; margin: 0 0 10px; border-bottom: 1px solid #dcdcde; }'
			. ' .theshed-cover-panel__tab { padding: 4px 0 6px; border-bottom: 2px solid transparent; color: #50575e; text-decoration: none; }'
			. ' .theshed-cover-panel__tab[aria-selected="true"] { color: #1d2327; border-bottom-color: #2271b1; font-weight: 600; }'
		);

		wp_enqueue_script( 'theshed-post-components-script', $this->version->get_plugin_url( 'public/scripts/post.min.js' ), array(), $this->version->get_plugin_version(), false );

		ob_start();
		?>
			window.Shorthand.WordPress.restApiUrl = <?php echo wp_json_encode( get_rest_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
			window.Shorthand.WordPress.pluginFilesUrl = <?php echo wp_json_encode( $this->version->get_plugin_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
			window.Shorthand.WordPress.ajaxApiUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
			window.Shorthand.WordPress.authState = <?php echo wp_json_encode( $auth_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
		<?php

		$post_components_src = ob_get_clean();

		wp_add_inline_script( 'theshed-post-components-script', $post_components_src, 'after' );

		// Inject the late-page toolbar creation code, right before the preview panel.
		wp_register_script( 'theshed-create-post-toolbar-script', false, array( 'theshed-post-components-script' ), $this->version->get_plugin_version(), true );
		wp_enqueue_script( 'theshed-create-post-toolbar-script' );

		ob_start();
		?>
			window.Shorthand.WordPress.ui.createPostEditorToolBar(
			document.getElementById('theshed-toolbar'),
			<?php echo wp_json_encode( get_the_ID(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>,
			<?php echo wp_json_encode( $edit_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>,
			<?php echo wp_json_encode( (array) $story_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>,
			<?php echo wp_json_encode( wp_create_nonce( 'shorthand_get_story_state' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>,
		);
		<?php if ( '' !== $story_id ) : ?>
		window.Shorthand.WordPress.ui.refreshStoryCover(
			<?php echo wp_json_encode( get_the_ID(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>,
			<?php echo wp_json_encode( wp_create_nonce( 'shorthand_story_cover' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>
		);
		<?php endif; ?>
		<?php

		$create_toolbar_src = ob_get_clean();

		wp_add_inline_script( 'theshed-create-post-toolbar-script', $create_toolbar_src, 'after' );
	}

	/**
	 * Checks the story's pull when its edit screen opens.
	 *
	 * Runs on `load-post.php`, before `post.php` reads the post. A pull the
	 * watch fails puts the post back to its prior status, and the publish
	 * box must show that status, not the one read before the check.
	 */
	public function load_edit_screen(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads which post the edit screen opens; core checks access.
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'edit' !== $action || ! $post_id || get_post_type( $post_id ) !== $this->post_type ) {
			return;
		}

		if ( $this->permissions->can_pull_story( $post_id ) ) {
			$this->pull_watch->check( $post_id );
		}
	}

	public function ajax_get_story_state() {
		if ( empty( $_GET['post'] ) ) {
			wp_send_json_error( new WP_Error( 'pretty', 'Post ID is required.' ), 401 );
			return;
		}

		check_ajax_referer( 'shorthand_get_story_state', '_ajax_nonce' );

		$post_id = absint( $_GET['post'] );
		if ( ! $this->permissions->can_pull_story( $post_id ) ) {
			wp_send_json_error( new WP_Error( 'pretty', __( 'You are not allowed to view this story.', 'the-shorthand-editor' ) ), 403 );
			return;
		}

		/* A pull the watch fails comes back with its error and no progress, which ends the client's polling. */
		$this->pull_watch->check( $post_id );

		$data = $this->get_post_story_state( $post_id );

		wp_send_json_success( $data );
	}

	/**
	 * Rebuilds the Featured image box around the Shorthand cover.
	 *
	 * The cover the last publish recorded shows first; core's own markup,
	 * with the featured image and its links, sits under a second tab. The
	 * client asks Shorthand for the current cover once the page has loaded.
	 * Core re-runs this filter when the author sets or removes a featured
	 * image, passing the unsaved choice as `$thumbnail_id`.
	 *
	 * @param string   $content      The featured image box markup.
	 * @param int      $post_id      Post being edited.
	 * @param int|null $thumbnail_id Featured image the form shows.
	 */
	public function admin_post_thumbnail_html( $content, $post_id, $thumbnail_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $this->post_type || '' === $this->get_story_id( $post ) ) {
			return $content;
		}

		$cover = StoryCover::sanitize( get_post_meta( $post_id, 'story_cover', true ) );
		$state = null === $cover ? StoryCover::STATE_UNKNOWN : $this->story_cover->state( (int) $post_id, $cover, (int) $thumbnail_id );

		$panel      = $this->cover_panel( (int) $post_id, $cover, $state );
		$message    = $panel['message'];
		$importable = $panel['importable'];

		ob_start();
		include $this->version->get_plugin_path( 'assets/admin/partials/cover-panel.php' );

		return ob_get_clean();
	}

	/**
	 * The story's current cover and the state publishing would reach with it.
	 *
	 * Reads only. The recorded cover belongs to the publish path, or a
	 * refresh here would hide a changed cover from the next publish. The
	 * `thumbnail` parameter carries the form's unsaved featured image.
	 */
	public function ajax_get_story_cover() {
		list( $post_id, $story_id ) = $this->ajax_story_post( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked inside.

		$cover = $this->story_cover->fetch( $story_id, true );
		if ( is_wp_error( $cover ) ) {
			wp_send_json_error( $cover, 502 );
			return;
		}

		$thumbnail = isset( $_GET['thumbnail'] ) ? max( 0, (int) $_GET['thumbnail'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$state     = $this->story_cover->state( $post_id, $cover, $thumbnail );

		wp_send_json_success( $this->cover_panel( $post_id, $cover, $state ) );
	}

	/**
	 * Imports the story's cover as the featured image now, at the author's
	 * request, replacing whatever is featured.
	 */
	public function ajax_import_story_cover() {
		list( $post_id, $story_id ) = $this->ajax_story_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked inside.

		$outcome = $this->story_cover->sync( $post_id, $story_id, true );
		if ( StoryCover::OUTCOME_FAILED === $outcome ) {
			wp_send_json_error( new WP_Error( 'pretty', 'The story cover could not be imported.' ), 502 );
			return;
		}
		if ( StoryCover::STATE_NONE === $outcome ) {
			wp_send_json_error( new WP_Error( 'pretty', 'This story has no cover image.' ), 404 );
			return;
		}

		$cover = $this->story_cover->fetch( $story_id, true );
		$state = $this->story_cover->state( $post_id, is_wp_error( $cover ) ? null : $cover );

		wp_send_json_success( $this->cover_panel( $post_id, is_wp_error( $cover ) ? null : $cover, $state ) );
	}

	/**
	 * Checks the nonce, the capability and the story link shared by the
	 * cover ajax actions. Ends the request on failure.
	 *
	 * @param array<string, mixed> $request `$_GET` or `$_POST`.
	 * @return array{0: int, 1: string} Post ID and story ID.
	 */
	private function ajax_story_post( array $request ): array {
		if ( empty( $request['post'] ) ) {
			wp_send_json_error( new WP_Error( 'pretty', 'Post ID is required.' ), 400 );
		}

		check_ajax_referer( 'shorthand_story_cover', '_ajax_nonce' );

		$post_id = absint( $request['post'] );
		if ( ! $this->permissions->can_pull_story( $post_id ) ) {
			wp_send_json_error( new WP_Error( 'pretty', 'You are not allowed to change this story.' ), 403 );
		}

		$post     = get_post( $post_id );
		$story_id = $post instanceof WP_Post ? $this->get_story_id( $post ) : '';
		if ( '' === $story_id ) {
			wp_send_json_error( new WP_Error( 'pretty', 'This post is not linked to a Shorthand story.' ), 404 );
		}

		return array( $post_id, $story_id );
	}

	/**
	 * What the cover panel shows for a state.
	 *
	 * @param int        $post_id Post whose featured image is reported.
	 * @param array|null $cover   Incoming cover.
	 * @param string     $state   A `StoryCover::STATE_*` value.
	 * @return array{cover: array|null, state: string, message: string, importable: bool, thumbnail: int}
	 */
	private function cover_panel( int $post_id, ?array $cover, string $state ): array {
		return array(
			'cover'      => $cover,
			'state'      => $state,
			'message'    => $this->story_cover->describe( $state ),
			'importable' => null !== $cover && StoryCover::STATE_CURRENT !== $state,
			'thumbnail'  => get_post_thumbnail_id( $post_id ),
		);
	}

	public function get_post_story_state( int $post_id ): ?array {
		$live_version  = $this->post_api->get_post_story_version( $post_id );
		$error         = $this->post_api->get_story_update_error( $post_id );
		$state         = $error ? null : $this->post_api->get_story_update_progress( $post_id );
		$pending_title = $this->title_sync->get_pending( $post_id );
		$notice        = $this->post_api->get_story_update_notice( $post_id );

		return ( new StorySyncState( $live_version, $error, $state, $pending_title, $notice ) )->to_array();
	}
}
