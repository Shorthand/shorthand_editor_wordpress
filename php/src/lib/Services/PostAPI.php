<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use Shorthand\Services\Options;
use Shorthand\Services\Permissions;

use ZipArchive;

use WP_REST_Request;

use WP_Post;
use WP_Error;


class PostAPI {

	/**
	 * @var \Shorthand\Services\Shorthand
	 */
	private $shorthand;
	/**
	 * @var \Shorthand\Services\Options
	 */
	private $options;
	/**
	 * @var \Shorthand\Services\Permissions
	 */
	private $permissions;
	/**
	 * @var string
	 */
	private $post_type;
	/**
	 * @var \Shorthand\Services\StoryContentTransformer
	 */
	private $content_transformer;
	/**
	 * @var \Shorthand\Services\AuthStateManager
	 */
	private $auth_state_manager;
	/**
	 * Derives the plain text stored on the post.
	 *
	 * @var \Shorthand\Services\StoryTextExtractor
	 */
	private $text_extractor;
	/**
	 * Guards the plugin's own write against the publish hooks it fires.
	 *
	 * @var bool
	 */
	private $storing_text = false;

	public function __construct( Shorthand $shorthand, Options $options, Permissions $permissions, string $post_type, AuthStateManager $auth_state_manager, StoryContentTransformer $content_transformer, StoryTextExtractor $text_extractor ) {
		$this->shorthand           = $shorthand;
		$this->options             = $options;
		$this->permissions         = $permissions;
		$this->post_type           = $post_type;
		$this->content_transformer = $content_transformer;
		$this->auth_state_manager  = $auth_state_manager;
		$this->text_extractor      = $text_extractor;
	}

	/**
	 * Whether a story's own text is being written back to its post right now.
	 *
	 * `store_story_text()` calls `wp_update_post()`, which fires the same save
	 * hooks that publishing runs on. Editor checks this before acting on them,
	 * so the write does not schedule a second publish.
	 */
	public function is_storing_text(): bool {
		return $this->storing_text;
	}

	/**
	 * Create or link a local WordPress post for a Shorthand story.
	 *
	 * When `$post_id` is null, a new post of the configured post type is
	 * created with the story's title, in the given `$post_status` (`draft`
	 * by default, matching `wp_insert_post()`'s own default). The new post
	 * is then linked to the story via the `story_id` meta and the story's
	 * `externalId` is pushed back to Shorthand.
	 *
	 * @param string   $story_id    Shorthand story ID to connect.
	 * @param int|null $post_id     Existing post ID. Currently unsupported; passing a value terminates the request.
	 * @param string   $post_status Status to create the new post with when `$post_id` is null.
	 * @return \WP_Post|\WP_Error The linked post, or a WP_Error should linking to Shorthand fail after creation.
	 */
	public function connect_story( string $story_id, ?int $post_id, string $post_status = 'draft' ) {
		if ( ! $post_id ) {
			$title = 'Add your title';

			$story_settings = $this->shorthand->get_story_settings( $story_id );
			if ( is_wp_error( $story_settings ) ) {
				wp_die(
					esc_html( $story_settings->get_error_message() ),
					esc_html__( 'Error getting story info.', 'the-shorthand-editor' )
				);
			}
			$story_info = isset( $story_settings['meta'] ) ? $story_settings['meta'] : array();
			if ( isset( $story_info['title'] ) ) {
				$title = sanitize_text_field( $story_info['title'] );
			}
			if ( isset( $story_info['description'] ) ) {
				$description = sanitize_textarea_field( $story_info['description'] );
			}

			$post_id = wp_insert_post(
				array(
					'post_title'  => $title,
					'post_type'   => $this->post_type,
					'post_status' => $post_status,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				wp_die(
					esc_html( $post_id->get_error_message() ),
					esc_html__( 'Error creating post.', 'the-shorthand-editor' )
				);
			}
		} else {
			wp_die(
				esc_html__( 'Action is not supported.', 'the-shorthand-editor' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die(
				esc_html__( 'Post not found.', 'the-shorthand-editor' )
			);
		}

		$story_id = sanitize_text_field( $story_id );
		update_post_meta( $post_id, 'story_id', $story_id );

		$err = $this->shorthand->set_story_external_id( $story_id, $post_id );
		if ( is_wp_error( $err ) ) {
			wp_die(
				esc_html( $err->get_error_message() ),
				esc_html__( 'Error linking post to story.', 'the-shorthand-editor' ),
				array(
					'back_link' => true,
				)
			);
		}

		return $post;
	}

	public function get_story_update_error( int $post_id ): ?array {
		$error = get_post_meta( $post_id, 'story_update_error', true );
		return is_array( $error ) ? $error : null;
	}


	public function set_story_update_error( int $post_id, ?\WP_Error $error = null ) {
		if ( ! isset( $error ) ) {
			delete_post_meta( $post_id, 'story_update_error' );
		} elseif ( is_wp_error( $error ) ) {
			update_post_meta( $post_id, 'story_update_error', $this->get_wp_error_as_array( $error ) );
		}
	}

	public function get_story_update_progress( int $post_id ): ?StorySyncProgress {
		return StorySyncProgress::from_meta_value( get_post_meta( $post_id, 'story_update_state', true ) );
	}

	public function set_story_update_progress( int $post_id, ?StorySyncProgress $progress = null ) {
		if ( ! isset( $progress ) ) {
			delete_post_meta( $post_id, 'story_update_state' );
		} else {
			update_post_meta( $post_id, 'story_update_state', $progress->to_array() );
		}
	}

	/**
	 * Check if user has permissions to pull a post's associated Shorthand story into
	 * WordPress.
	 *
	 * Only those with editing or publishing permissions can pull a Shorthand story
	 *
	 * @param mixed $request
	 * @return bool
	 */
	public function has_pull_story_permission( WP_REST_Request $request ) {
		$nonce = sanitize_text_field( wp_unslash( $request->get_header( 'x-wp-nonce' ) ) );
		if ( ! isset( $nonce ) || ! wp_verify_nonce( $nonce, 'wp-rest-pull-story' ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( $user->ID === 0 ) {
			return false;
		}

		$post_id = \intval( $request['post_id'] );
		if ( ! $post_id || $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		return $this->permissions->can_pull_story( $post_id );
	}

	/**
	 * @return \Shorthand\Services\StoryUpdateTask|\WP_Error
	 */
	public function pull_story_begin( int $post_id ) {
		if ( ! $this->auth_state_manager->is_connected() ) {
			return new WP_Error( 'auth', __( 'Cannot publish: the Shorthand connection is not active.', 'the-shorthand-editor' ) );
		}

		/* abort any outstanding requests by updating the nonce */
		$request_nonce = $this->reset_story_pull_request_nonce( $post_id );

		$this->set_story_update_error( $post_id );
		$this->set_story_update_progress( $post_id, new StorySyncProgress( 0, 'Requesting story from Shorthand' ) );

		$story_id = get_post_meta( $post_id, 'story_id', true );
		if ( ! $story_id ) {
			return new WP_Error( 'pretty', 'Post does not have a Shorthand story associated with it' );
		}

		$download_url = $this->post_download_request( $story_id );

		if ( is_wp_error( $download_url ) ) {
			return $download_url;
		}

		$destination_path = $this->get_default_story_bundle_path( $post_id, $story_id );
		$storage_path     = "{$destination_path}_{$request_nonce}";

		FileSystem::init();
		wp_mkdir_p( $storage_path );

		return new StoryUpdateTask(
			$post_id,
			$story_id,
			$request_nonce,
			get_post_status( $post_id ),
			$download_url,
			$storage_path
		);
	}

	private function reset_story_pull_request_nonce( int $post_id ): string {
		$value = wp_rand( 10000, 99999 );
		$nonce = "{$value}";
		update_post_meta( $post_id, 'story_update_nonce', $nonce );
		return $nonce;
	}

	/**
	 * @return string|\WP_Error
	 */
	private function post_download_request( string $story_id ) {
		$url = add_query_arg(
			array(
				'story' => $story_id,
			),
			$this->options->get_api_url() . '/v2/stories/' . $story_id . '/generate'
		);

		$response = $this->shorthand->shorthand_api_authed_request( $url, 'POST' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$payload     = json_decode( $body );

		if ( 202 !== $status_code ) {
			$error = new WP_Error( 'story', "Shorthand story ID is {$story_id}.", $story_id );
			$error->add( 'status', "Received HTTP status {$status_code}.", $status_code );
			$this->add_error_params( $error, $payload );
			return $error;
		}

		$download_url = wp_remote_retrieve_header( $response, 'Location' );
		$download_url = $this->fix_api_url( $download_url );

		return $download_url;
	}

	private function add_error_params( WP_Error $error, $payload ): void {
		if ( ! empty( $payload->code ) ) {
			$error->add( 'code', "The error responsible was {$payload->code}.", $payload->code );
		}
		if ( ! empty( $payload->message ) ) {
			$error->add( 'pretty', $payload->message );
		}
	}

	private function get_temp_download_file_path( string $post_id ): string {
		FileSystem::init();

		$temp_file = wp_tempnam( "sh_download_{$post_id}", get_temp_dir() );
		return $temp_file;
	}

	private function check_pull_story_status( StoryUpdateTask $args ): bool {
		$nonce = get_post_meta( $args->post_id, 'story_update_nonce', true );
		return $nonce === $args->request_nonce;
	}

	private function check_file_url( StoryUpdateTask $args ): ?\WP_Error {
		if ( $args->file_url ) {
			return null;
		}

		$response = $this->shorthand->shorthand_api_authed_request( $args->download_url, 'GET' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		$file_url = wp_remote_retrieve_header( $response, 'Location' );

		$body    = wp_remote_retrieve_body( $response );
		$payload = json_decode( $body );

		if ( 202 === $status_code ) {
			return new WP_Error( 'retry', 'File download not ready.', 5 ); /* 5 second retry */
		}

		if ( 302 !== $status_code ) {
			$error = new WP_Error( 'status', "Download query received HTTP status {$status_code}.", $status_code );
			$this->add_error_params( $error, $payload );
			return $error;
		}

		$content_version = is_int( $payload->contentVersion ) ? $payload->contentVersion : null;

		$args->file_url        = $this->fix_api_url( $file_url );
		$args->content_version = $content_version;

		$response    = $this->shorthand->shorthand_api_authed_request( $args->file_url, 'HEAD' );
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$error = new WP_Error( 'pretty', 'An error occurred while requesting the story from Shorthand' );
			$error->add( 'status', "File size query received HTTP status {$status_code}.", $status_code );
			return $error;
		}

		$args->size = \intval( wp_remote_retrieve_header( $response, 'Content-Length' ) );
		return null;
	}

	public function fix_api_url( string $url ): string {
		if ( strncmp( $url, 'https://localhost', strlen( 'https://localhost' ) ) === 0 ) {
			return str_replace( 'https://localhost', 'https://host.docker.internal', $url );
		}
		return $url;
	}

	/**
	 * @return null|int|\WP_Error
	 */
	public function pull_story_cron( StoryUpdateTask $args ) {
		if ( ! $this->check_pull_story_status( $args ) ) {
			/* terminate this request immediately if there is a new request in flight */
			return null;
		}

		$res = $this->check_file_url( $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$args->ensure_chunk_window();

		if ( $args->is_download_complete() ) {
			/* this request has been completed */
			return $this->pull_story_completed( $args );
		}

		return $this->pull_story_chunk( $args );
	}

	/**
	 * @return int|\WP_Error
	 */
	private function pull_story_chunk( StoryUpdateTask $args ) {
		$file_path = $this->get_download_chunk_file_path( $args->files, $args );

		$url      = $args->file_url;
		$start    = $args->start;
		$end      = $args->end - 1;
		$response = $this->shorthand->shorthand_api_authed_request(
			$url,
			'GET',
			array(
				'stream'   => true,
				'filename' => $file_path,
				'headers'  => array(
					'Range' => "bytes={$start}-{$end}",
				),
			)
		);

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $status_code !== 206 ) {
			return new WP_Error( 'status', "Pulling story chunk received HTTP status {$status_code}.", $status_code );
		}

		$args->mark_chunk_downloaded();

		$progress = $args->get_progress_percent( 90 );

		$this->set_story_update_progress( $args->post_id, new StorySyncProgress( $progress, 'Saving story to WordPress' ) );

		return new WP_Error( 'retry', 'Request further file data', 0 );
	}

	private function get_download_chunk_file_path( int $chunk_number, StoryUpdateTask $args ): string {
		return "{$args->storage_path}/file-{$chunk_number}.part";
	}

	public function pull_story_failed( StoryUpdateTask $args, WP_Error $result ): void {
		if ( ! $this->check_pull_story_status( $args ) ) {
			return;
		}

		$result->add( 'post', "Post ID {$args->post_id}.", $args->post_id );

		$this->set_story_update_error( $args->post_id, $result );

		// Restore the original post status
		$status = get_post_status( $args->post_id );
		if ( $status !== $args->prior_status ) {
			wp_update_post(
				array(
					'ID'          => $args->post_id,
					'post_status' => $args->prior_status,
				)
			);
		}

		$this->pull_story_cleanup( $args );
	}

	private function pull_story_cleanup( StoryUpdateTask $args ): void {
		FileSystem::init();
		global $wp_filesystem;

		for ( $idx = 0; $idx < $args->files; $idx++ ) {
			$file_path = $this->get_download_chunk_file_path( $idx, $args );
			wp_delete_file( $file_path );
		}

		$wp_filesystem->rmdir( $args->storage_path );
	}

	public function pull_story_completed( StoryUpdateTask $args ): ?\WP_Error {
		FileSystem::init();

		$zip_file_path = $this->get_temp_download_file_path( $args->post_id );

		for ( $idx = 0; $idx < $args->files; $idx++ ) {
			$file_path = $this->get_download_chunk_file_path( $idx, $args );
			if ( ! FileSystem::concat_file( $file_path, $zip_file_path ) ) {
				$error = new WP_Error( 'file', 'Failed to assemble story download.', $zip_file_path );
				return $error;
			}
		}

		$story = $this->extract_story_content( $zip_file_path, $args->post_id, $args->story_id );
		if ( is_wp_error( $story ) ) {
			return $story;
		}

		$this->set_story_update_progress( $args->post_id );

		$this->set_post_story_version( $args->post_id, (int) $args->content_version );

		$this->pull_story_cleanup( $args );
		return null;
	}

	/**
	 * @return mixed[]|\WP_Error
	 */
	public function pull_story( $story_id, $post_id, $without_assets = false, $assets_only = false ) {
		FileSystem::init();

		// Attempt to connect to the server.
		$zip_file = wp_tempnam( "sh_zip_{$post_id}", get_temp_dir() );

		$args = array();
		if ( $without_assets ) {
			$args['without_assets'] = 1;
		}
		if ( $assets_only ) {
			$args['assets_only'] = 1;
		}

		$url = $this->options->get_api_url() . '/v2/stories/' . $story_id;
		$url = add_query_arg( $args, $url );

		$response = $this->shorthand->shorthand_api_authed_request(
			$url,
			'GET',
			array(
				'timeout'  => '600',
				'stream'   => true,
				'filename' => $zip_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->set_story_update_error( $post_id, $response );
			wp_die(
				esc_html( $response->get_error_message() ),
				esc_html__( 'Error publishing story', 'the-shorthand-editor' ),
				array( 'back_link' => true )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body    = wp_remote_retrieve_body( $response );
			$payload = json_decode( $body );

			$error = $this->get_error_from_payload( $story_id, $payload, $status_code );
			$this->set_story_update_error( $post_id, $error );

			wp_die(
				esc_html( isset( $payload->message ) ? $payload->message : __( 'An error occurred while publishing the story.', 'the-shorthand-editor' ) ),
				esc_html__( 'Error publishing story', 'the-shorthand-editor' ),
				array(
					'additional_errors' => array(
						array( 'message' => esc_html( "The request returned HTTP status code {$status_code}." ) ),
					),
					'back_link'         => true,
				)
			);
		}

		$content_version = wp_remote_retrieve_header( $response, 'content-version' );
		$content_version = is_numeric( $content_version ) ? (int) $content_version : null;

		return array(
			'zip_file'        => $zip_file,
			'content_version' => $content_version,
		);
	}

	private function get_error_from_payload( $story_id, $payload, $status_code ): WP_Error {
		$error = new WP_Error( 'story', "The Shorthand story ID is {$story_id}.", $story_id );
		$error->add( 'status', "Received HTTP status {$status_code}.", $status_code );
		$this->add_error_params( $error, $payload );
		return $error;
	}

	public function get_post_story_version( $post_id ): ?int {
		$version = get_post_meta( $post_id, 'story_version', true );
		$version = ! empty( $version ) || '0' === $version ? (int) $version : null;
		return $version;
	}

	public function set_post_story_version( int $post_id, ?int $content_version ): void {
		if ( isset( $content_version ) ) {
			update_post_meta( $post_id, 'story_version', $content_version );
		} else {
			delete_post_meta( $post_id, 'story_version' );
		}
	}

	public function extract_story_content( $zip_file, $post_id, $story_id ): ?\WP_Error {
		$story_path = wp_upload_dir()['basedir'] . '/shorthand/' . $post_id . '/' . $story_id;
		$story      = $this->unzip_story( $zip_file, $story_path );
		if ( is_wp_error( $story ) ) {
			$error = new WP_Error( 'story', 'Story being published', $story_id );
			$error->merge_from( $story );
			return $error;
		}

		wp_delete_file( $zip_file );

		$story['path'] = $story_path;

		$head    = $story['head'];
		$article = $story['article'];

		$bundle_url  = $this->get_story_bundle_url( $post_id, $story_id );
		$bundle_path = $this->get_story_bundle_path( $post_id, $story_id );

		$head    = $this->content_transformer->rewrite_story_bundle_paths( $bundle_url, $head );
		$article = $this->content_transformer->rewrite_story_bundle_paths( $bundle_url, $article );

		$head    = apply_filters( 'theshed_fix_content_paths', $head );
		$article = apply_filters( 'theshed_fix_content_paths', $article );

		$transformed_story = $this->content_transformer->apply_processing_rule_set(
			$head,
			$article,
			$this->options->get_post_regex_list()
		);
		$head              = $transformed_story['head'];
		$article           = $transformed_story['article'];

		$article = apply_filters( 'theshed_post_process_body', $article, $bundle_path, "{$bundle_path}/article.html" );
		$head    = apply_filters( 'theshed_post_process_head', $head, $bundle_path, "{$bundle_path}/head.html" );

		update_post_meta( $post_id, 'story_head', wp_slash( $head ) );
		update_post_meta( $post_id, 'story_body', wp_slash( $article ) );

		$this->store_story_text( $post_id, $article );

		return null;
	}

	/**
	 * Mirrors the story's text into `post_content` and `post_excerpt`.
	 *
	 * Neither column is rendered — `single-tse-story.php` builds the page from
	 * `story_body` — but core search reads no other columns, so this is what
	 * makes story prose findable and gives listing views something to show.
	 *
	 * @param int    $post_id Post being published.
	 * @param string $article The story bundle's `article.html`.
	 */
	private function store_story_text( int $post_id, string $article ): void {
		$text = $this->text_extractor->extract( $article );

		$update = array( 'ID' => $post_id );

		/**
		 * Filters the plain text stored as a story's `post_content`.
		 *
		 * @param string $content Text extracted from the story body.
		 * @param int    $post_id Post being published.
		 */
		$content = (string) apply_filters( 'theshed_story_content', $text['content'], $post_id );
		if ( '' !== $content ) {
			$update['post_content'] = wp_slash( $content );
		}

		$excerpt = $this->prepare_story_excerpt( $post_id, $text['prose'] );
		if ( null !== $excerpt ) {
			$update['post_excerpt'] = wp_slash( $excerpt );
		}

		if ( 1 === count( $update ) ) {
			return;
		}

		$this->storing_text = true;
		try {
			$result = wp_update_post( $update, true );
		} finally {
			$this->storing_text = false;
		}

		if ( is_wp_error( $result ) || ! isset( $update['post_excerpt'] ) ) {
			return;
		}

		/*
		 * Record what the column actually holds. `excerpt_save_pre` re-encodes
		 * entities, so comparing the next publish against the value passed in
		 * would read our own excerpt as an author's edit.
		 */
		update_post_meta( $post_id, 'story_excerpt', wp_slash( (string) get_post_field( 'post_excerpt', $post_id, 'raw' ) ) );
	}

	/**
	 * Builds the excerpt, unless the post already carries one worth keeping.
	 *
	 * @param int    $post_id Post being published.
	 * @param string $prose   Story body text, without the title section.
	 * @return string|null Null when nothing should be written.
	 */
	private function prepare_story_excerpt( int $post_id, string $prose ): ?string {
		if ( '' === $prose ) {
			return null;
		}

		$current = (string) get_post_field( 'post_excerpt', $post_id, 'raw' );
		$stored  = (string) get_post_meta( $post_id, 'story_excerpt', true );

		// An excerpt the author wrote or edited is theirs; only replace ours.
		if ( '' !== trim( $current ) && $current !== $stored ) {
			return null;
		}

		/**
		 * Filters the word count of a generated story excerpt.
		 *
		 * @param int $length  Words to keep. Defaults to the core excerpt length.
		 * @param int $post_id Post being published.
		 */
		$length = (int) apply_filters( 'theshed_story_excerpt_length', 55, $post_id );

		$excerpt = wp_trim_words( $prose, $length, '' );

		/**
		 * Filters the generated story excerpt before it is stored.
		 *
		 * @param string $excerpt Trimmed excerpt.
		 * @param int    $post_id Post being published.
		 * @param string $prose   Full story body text it was trimmed from.
		 */
		$excerpt = (string) apply_filters( 'theshed_story_excerpt', $excerpt, $post_id, $prose );

		return '' === trim( $excerpt ) ? null : $excerpt;
	}

	private function unzip_story( $zip_file, $story_path ) {
		$zip = new ZipArchive();
		$ok  = $zip->open( $zip_file );
		if ( $ok !== true ) {
			$file_size = wp_filesize( $zip_file );
			$err       = new WP_Error( 'file', "Could not open story archive at {$zip_file}.", $zip_file );
			$err->add( 'file_size', "File size is {$file_size}.", $file_size );
			$err->add( 'zip', self::get_zip_error_message( $ok ), $ok );
			return $err;
		}

		wp_mkdir_p( $story_path );

		$head    = $zip->getFromName( 'head.html' );
		$article = $zip->getFromName( 'article.html' );

		if ( ! $zip->extractTo( $story_path ) || ! $zip->close() ) {
			$file_size = wp_filesize( $zip_file );
			$err       = new WP_Error( 'file', "Could not extract story archive at {$zip_file}.", $zip_file );
			$err->add( 'file_size', "File size is {$file_size}.", $file_size );
			$err->add( 'zip', $zip->getStatusString(), $zip->status );
			return $err;
		}

		if ( ! $head ) {
			$head = '';
		}

		if ( ! $article ) {
			$article = '';
		}

		return array(
			'head'    => $head,
			'article' => $article,
		);
	}

	public function get_story_bundle_url( $post_id, $story_id ): string {
		$destination_url = wp_upload_dir()['baseurl'] . '/shorthand/' . $post_id . '/' . $story_id;

		$destination_url = apply_filters( 'theshed_get_story_url', $destination_url );

		return $destination_url;
	}

	public function get_story_bundle_path( $post_id, $story_id ): string {
		return $this->get_default_story_bundle_path( $post_id, $story_id );
	}

	private function get_default_story_bundle_path( $post_id, $story_id ): string {
		$destination_path = wp_upload_dir()['basedir'] . '/shorthand/' . $post_id . '/' . $story_id;

		return $destination_path;
	}

	public function delete_story_bundle( int $post_id, string $story_id ): void {
		FileSystem::init();
		global $wp_filesystem;

		$bundle_path = $this->get_story_bundle_path( $post_id, $story_id );
		if ( $wp_filesystem->exists( $bundle_path ) ) {
			$wp_filesystem->delete( $bundle_path, true );
		}

		$post_path = dirname( $bundle_path );
		if ( $wp_filesystem->exists( $post_path ) && $wp_filesystem->is_dir( $post_path ) ) {
			$wp_filesystem->delete( $post_path, true );
		}
	}

	public function get_preview_content( $post_id ): ?StoryPreview {
		$story_id = get_post_meta( $post_id, 'story_id', true );
		if ( ! $story_id ) {
			return null;
		}

		$response = $this->shorthand->shorthand_api_authed_request(
			$this->options->get_api_url() . '/v2/stories/' . $story_id . '/preview',
			'GET'
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ) );

		$content_version = wp_remote_retrieve_header( $response, 'content-version' );

		if ( is_array( $content_version ) ) {
			$content_version = isset( $content_version[0] ) ? $content_version[0] : '';
		}

		$content_version = ! empty( $content_version ) ? (int) $content_version : null;
		$preview         = StoryPreview::from_payload( $payload, $content_version );
		if ( null === $preview ) {
			return null;
		}

		$transformed_preview = $this->content_transformer->apply_processing_rule_set(
			$preview->get_head(),
			$preview->get_body(),
			$this->options->get_post_regex_list()
		);

		return $preview->with_content( $transformed_preview['head'], $transformed_preview['article'] );
	}

	public function get_wp_error_as_array( WP_Error $error ): array {
		$result = array();
		if ( $error instanceof WP_Error ) {
			foreach ( $error->get_error_codes() as $code ) {
				$result[] = array(
					'message' => $error->get_error_message( $code ),
					'data'    => $error->get_error_data( $code ),
					'code'    => $code,
				);
			}
		}
		return $result;
	}

	private static function get_zip_error_message( $err ) {
		if ( false === $err ) {
			return 'Unknown error.';
		}

		switch ( $err ) {
			case ZipArchive::ER_EXISTS:
				return 'File already exists.';
			case ZipArchive::ER_INCONS:
				return 'Zip archive inconsistent.';
			case ZipArchive::ER_INVAL:
				return 'Invalid argument.';
			case ZipArchive::ER_MEMORY:
				return 'Malloc failure.';
			case ZipArchive::ER_NOENT:
				return 'No such file.';
			case ZipArchive::ER_NOZIP:
				return 'Not a zip archive.';
			case ZipArchive::ER_OPEN:
				return 'Can\'t open file.';
			case ZipArchive::ER_READ:
				return 'Read error.';
			case ZipArchive::ER_SEEK:
				return 'Seek error.';
		}
		return "Error code {$err}.";
	}
}
