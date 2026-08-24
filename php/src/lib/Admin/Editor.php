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
use Shorthand\Services\PostAPI;
use Shorthand\Services\StorySyncState;
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

	public function __construct(
		Options $options,
		Shorthand $shorthand,
		Cron $cron,
		Version $version,
		PostAPI $post_api,
		PostPreview $post_preview,
		EditWithShorthand $edit_with_shorthand,
		string $post_type,
		AuthStateManager $auth_state_manager
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
	}

	public function init( Loader $loader ) {
		$loader->add_filter( 'wp_insert_post_data', $this, 'wp_insert_post_data', 10, 4 );

		$loader->add_action( "save_post_{$this->post_type}", $this, 'save_shorthand_story', 10, 2 );

		$loader->add_action( 'edit_form_after_title', $this, 'edit_form_after_title', 10, 1 );

		$loader->add_filter( 'post_row_actions', $this, 'row_action_edit_with_shorthand', 10, 2 );
		$loader->add_filter( 'preview_post_link', $this, 'preview_post_link', 10, 2 );

		$loader->add_filter( 'admin_enqueue_scripts', $this, 'admin_enqueue_scripts', 10, 1 );

		$loader->add_action( 'wp_ajax_shorthand_get_story_state', $this, 'ajax_get_story_state', 10, 1 );

		$loader->add_action( 'before_delete_post', $this, 'before_delete_post', 10, 2 );
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

		$post_id = $postarr['ID'];

		$old_title = get_post_field( 'post_title', $postarr['ID'], 'raw' );
		$new_title = stripslashes( $data['post_title'] );

		if ( $old_title !== $new_title && $this->auth_state_manager->is_connected() ) {
			$story_id = get_post_meta( $post_id, 'story_id', true );
			if ( isset( $story_id ) && $story_id ) {
				$this->shorthand->set_story_title( $story_id, $new_title );
			}
		}

		if ( ! $this->is_publishing_status( $data['post_status'] ) ) {
			return $data;
		}

		if ( ! $this->auth_state_manager->is_connected() ) {
			$this->post_api->set_story_update_error(
				$post_id,
				new WP_Error( 'auth', __( 'Cannot publish: the Shorthand connection is not active.', 'the-shorthand-editor' ) )
			);
			$data['post_status'] = get_post_status( $post_id );
			return $data;
		}

		$this->post_api->set_story_update_progress( $post_id );
		$this->post_api->set_story_update_error( $post_id );

		$result = $this->cron->schedule_pull_story( $post_id );

		if ( is_wp_error( $result ) || ! $result ) {
			/* if the cron job fails, it should fall back to the original status */
			$this->post_api->set_story_update_error( $post_id, $result );
			$data['post_status'] = get_post_status( $post_id );
			return $data;
		}

		return $data;
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
		return in_array( $status, array( 'publish', 'future' ), true );
	}

	public function edit_form_after_title( $post ) {
		if ( $post->post_type !== $this->post_type ) {
			return;
		}

		$this->render_story_preview( $post );
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


	public function preview_post_link( string $url, WP_Post $post ): string {
		if ( $post->post_type !== $this->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $url;
		}

		return $this->post_preview->get_preview_url( $post );
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
		<?php

		$create_toolbar_src = ob_get_clean();

		wp_add_inline_script( 'theshed-create-post-toolbar-script', $create_toolbar_src, 'after' );
	}

	public function ajax_get_story_state() {
		if ( empty( $_GET['post'] ) ) {
			wp_send_json_error( new WP_Error( 'pretty', 'Post ID is required.' ), 401 );
			return;
		}

		check_ajax_referer( 'shorthand_get_story_state', '_ajax_nonce' );

		$post_id = absint( $_GET['post'] );
		$data    = $this->get_post_story_state( $post_id );

		wp_send_json_success( $data );
	}

	public function get_post_story_state( int $post_id ): ?array {
		$live_version = $this->post_api->get_post_story_version( $post_id );
		$error = $this->post_api->get_story_update_error( $post_id );
		$state = $error ? null : $this->post_api->get_story_update_progress( $post_id );

		return ( new StorySyncState( $live_version, $error, $state ) )->to_array();
	}
}
