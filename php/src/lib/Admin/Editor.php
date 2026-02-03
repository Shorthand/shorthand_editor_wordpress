<?php

namespace Shorthand\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Core\Version;
use Shorthand\Services\Options;
use Shorthand\Services\Shorthand;
use Shorthand\Services\PostAPI;
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

	public function __construct(
		Options $options,
		Shorthand $shorthand,
		Cron $cron,
		Version $version,
		PostAPI $post_api,
		PostPreview $post_preview,
		EditWithShorthand $edit_with_shorthand,
		string $post_type
	) {
		$this->post_type           = $post_type;
		$this->options             = $options;
		$this->shorthand           = $shorthand;
		$this->cron                = $cron;
		$this->version             = $version;
		$this->post_api            = $post_api;
		$this->post_preview        = $post_preview;
		$this->edit_with_shorthand = $edit_with_shorthand;
	}

	public function init( Loader $loader ) {
		$loader->add_filter( 'wp_insert_post_data', $this, 'wp_insert_post_data', 10, 4 );

		$loader->add_action( "save_post_{$this->post_type}", $this, 'save_shorthand_story', 10, 2 );

		// $loader->add_filter("status_save_pre", $this, 'status_save_pre', 10, 2);
		// $loader->add_action( "add_meta_boxes_{$this->post_type}", $this, 'add_meta_boxes_for_post_type', 10, 1 );

		$loader->add_action( 'edit_form_after_title', $this, 'edit_form_after_title', 10, 1 );

		$loader->add_filter( 'post_row_actions', $this, 'row_action_edit_with_shorthand', 10, 2 );
		// $loader->add_filter( 'edit_form_before_permalink', $this, 'edit_form_before_permalink', 10, 1 );
		$loader->add_filter( 'preview_post_link', $this, 'preview_post_link', 10, 2 );

		$loader->add_filter( 'admin_enqueue_scripts', $this, 'admin_enqueue_scripts', 10, 1 );

		$loader->add_action( 'wp_ajax_shorthand_get_story_state', $this, 'ajax_get_story_state', 10, 1 );
	}

	public function get_story_id( WP_Post $post ): string {
		return get_post_meta( $post->ID, 'story_id', true );
	}

	// public function status_save_pre(string $status): string
	// {
	// if (!$this->is_story_type()) {
	// return $status;
	// }

	// return $status;
	// }

	public function wp_insert_post_data( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		if ( ! $this->is_story_type( $data['post_type'] ) ) {
			return $data;
		}

		$post_id = $postarr['ID'];

		$old_title = get_post_field( 'post_title', $postarr['ID'], 'raw' );
		$new_title = stripslashes( $data['post_title'] );

		if ( $old_title !== $new_title ) {
			$story_id = get_post_meta( $post_id, 'story_id', true );
			if ( isset( $story_id ) && $story_id ) {
				$this->shorthand->set_story_title( $story_id, $new_title );
			}
		}

		if ( ! $this->is_publishing_status( $data['post_status'] ) ) {
			return $data;
		}

		$this->post_api->set_story_update_progress( $post_id );
		$this->post_api->set_story_update_error( $post_id );

		if ( ! $this->options->is_publishing_async() ) {
			return $data;
		}

		$result = $this->cron->schedule_pull_story( $post_id );

		if ( is_wp_error( $result ) || ! $result ) {
			/* if the cron job fails, it should fall back to the original status */
			$this->post_api->set_story_update_error( $post_id, $result );
			$data['post_status'] = get_post_status( $post_id );
			return $data;
		}

		return $data;
	}

	public function save_shorthand_story( $post_id, $post ) {
		if ( 'publish' !== $post->post_status && 'future' !== $post->post_status ) {
			$this->post_api->set_post_story_version( $post_id, null );
			return;
		}

		if ( $this->options->is_publishing_async() ) {
			/* Return early when publishing asynchronously (initiated in wp_insert_post_data) */
			return;
		}

		/* Publish synchronously (a debug override only) */

		$story_id = $this->get_story_id( $post );

		$pulled_story = $this->post_api->pull_story( $story_id, $post_id, false, false );

		$zip_file        = $pulled_story['zip_file'];
		$content_version = $pulled_story['content_version'];

		$error = $this->post_api->extract_story_content( $zip_file, $post_id, $story_id );

		if ( is_wp_error( $error ) ) {
			$this->post_api->set_story_update_error( $post_id, $error );
			wp_die(
				esc_html( $error->get_error_message() ),
				esc_html__( 'Error publishing story', 'the-shorthand-editor' ),
				array( 'back_link' => true )
			);
		}

		$this->post_api->set_post_story_version( $post_id, (int) $content_version );
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

		if ( ! $this->options->is_verified() ) {
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

		/* TODO: alternative content when token is not verified */

		// Inject the early-page toolbar components and styles.
		$edit_url    = $this->edit_with_shorthand->get_url( $post, $story_id );
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
		$data         = array(
			'errors'      => array( 'publishing' => null ),
			'liveVersion' => $live_version,
		);

		$error = $this->post_api->get_story_update_error( $post_id );
		if ( $error ) {
			$data['errors']['publishing'] = $error;
		} else {
			$state = $this->post_api->get_story_update_progress( $post_id );
			if ( $state && is_numeric( $state['percent'] ) ) {
				$data['progress'] = $state;
			}
		}

		return $data;
	}
}
