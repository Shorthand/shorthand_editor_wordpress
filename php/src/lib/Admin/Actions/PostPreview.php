<?php

namespace Shorthand\Admin\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Core\Version;
use Shorthand\Services\Permissions;


use Shorthand\Services\Options;
use Shorthand\Services\PostAPI;
use Shorthand\Services\StoryKses;

use WP_Post;
use WP_Error;

/**
 * Previews a Shorthand story in the WordPress environment
 *
 * This `render_page` hook should be registered at the
 * `admin_post_shorthand_preview` action, to coincide with
 * the `admin-post.php?action=shorthand_preview` URL.
 *
 * Query params:
 * `_wpnonce` - a WordPress nonce for the `shorthand_preview` action
 * `action` - must be `shorthand_preview`
 * `post` - the WP post ID
 */
class PostPreview {

	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;
	/**
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;
	/**
	 * @var \Shorthand\Services\Permissions
	 */
	private $permissions;
	/**
	 * @var \Shorthand\Core\Version
	 */
	private $version;

	public function __construct(
		Options $options,
		PostAPI $post_api,
		Permissions $permissions,
		Version $version
	) {
		$this->options     = $options;
		$this->post_api    = $post_api;
		$this->permissions = $permissions;
		$this->version     = $version;
	}

	/**
	 * Returns a preview URL
	 */
	public function get_preview_url( WP_Post $post ): string {
		$post_id_param = "&post={$post->ID}";
		$nonce_param   = '&_wpnonce=' . rawurlencode( wp_create_nonce( 'shorthand_preview' ) );
		return admin_url( 'admin-post.php?action=shorthand_preview' . $nonce_param . $post_id_param );
	}

	public function define_preview_page( Loader $loader ): void {
		/* when Shorthand needs to associate a story ID with a WP post, it redirects back to here */
		$loader->add_action( 'admin_post_shorthand_preview', $this, 'render_page' );
	}


	public function render_page() {
		set_current_screen( 'shorthand_preview' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );

		$err = new WP_Error();
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			$this->die_with_error( __( 'Invalid preview request: missing nonce.', 'the-shorthand-editor' ), 400 );
		}

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'shorthand_preview' ) ) {
			$this->die_with_error( __( 'Bad preview request: expired nonce.', 'the-shorthand-editor' ), 400 );
		}

		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : null;
		if ( ! $post_id ) {
			$this->die_with_error( __( 'A post ID is required for a preview request.', 'the-shorthand-editor' ), 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$this->die_with_error( __( 'You do not have permission to view this page.', 'the-shorthand-editor' ), 403 );
		}

		$preview_content = $this->post_api->get_preview_content( $post_id );
		if ( ! $preview_content ) {
			$this->die_with_error( __( 'The story does not exist in this Shorthand workspace. It may have been deleted, or your WordPress site may have been connected to a different workspace. Please contact your administrator.', 'the-shorthand-editor' ), 404 );
		}

		$story_version = $preview_content['content_version'];
		$story_head    = $preview_content['head'];
		$story_body    = $preview_content['body'];
		$user_style    = $this->options->get_post_css();

		// Enqueue scripts and stylesheets from story head content.
		if ( ! empty( $story_head ) ) {
			StoryKses::enqueue_head_assets( $story_head, false, $story_version );
		}

		wp_register_style( 'theshed-preview-user-style', false, array(), md5( $user_style ) );
		wp_enqueue_style( 'theshed-preview-user-style' );
		wp_add_inline_style( 'theshed-preview-user-style', wp_kses( $user_style, array(), array() ) );

		// Register handle for story scripts extracted during KSES filtering.
		// This is an inline script and does not have a version.
		wp_register_script( StoryKses::SCRIPT_HANDLE, false, array(), null, true ); /* phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion */

		// Store story head for echoing meta tags in the template.
		$story_head_for_meta = $story_head;

		wp_register_script(
			'theshed-preview-notify-loaded',
			false,
			array(),
			$this->version->get_plugin_version(),
			true
		);

		wp_add_inline_script(
			'theshed-preview-notify-loaded',
			$this->get_inline_loaded_script( $story_version ),
			'after'
		);

		wp_enqueue_script( 'theshed-preview-notify-loaded' );

		include $this->version->get_plugin_path( 'assets/admin/partials/preview-innerhtml.php' );
	}

	private function die_with_error( string $message, int $status ): void {
		$err = new WP_Error( 'pretty', 'The preview for this story is unavailable.' );
		$err->add( 'response', $message, $status );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			status_header( $status );
			nocache_headers();
		}

		wp_register_script(
			'theshed-preview-notify-error',
			false,
			array(),
			$this->version->get_plugin_version(),
			true
		);

		wp_add_inline_script(
			'theshed-preview-notify-error',
			$this->get_inline_error_script( $err ),
			'after'
		);

		wp_enqueue_script( 'theshed-preview-notify-error' );
		wp_enqueue_style(
			'theshed-preview-error-style',
			$this->version->get_plugin_url( 'assets/admin/partials/preview-error.css' ),
			array(),
			$this->version->get_plugin_version()
		);

		include $this->version->get_plugin_path( 'assets/admin/partials/preview-error.php' );

		die();
	}

	private function get_inline_error_script( WP_Error $err ): string {
		$preview_error = $this->post_api->get_wp_error_as_array( $err );

		ob_start();
		?>
		const errors = <?php echo wp_json_encode( array( 'preview' => $preview_error ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
		window.parent.postMessage({ event: "PreviewError", errors }, "*");
		<?php

		return ob_get_clean();
	}

	private function get_inline_loaded_script( ?int $story_version ): string {
		ob_start();
		?>
		const contentVersion = <?php echo wp_json_encode( $story_version, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
		window.parent.postMessage({ event: "PreviewLoaded", contentVersion: contentVersion }, "*");
		<?php

		return ob_get_clean();
	}
}
