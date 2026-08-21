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
	 * @var \Shorthand\Services\FileSystemService
	 */
	private $file_system;

	public function __construct( Shorthand $shorthand, Options $options, Permissions $permissions, string $post_type, AuthStateManager $auth_state_manager, StoryContentTransformer $content_transformer, FileSystemService $file_system ) {
		$this->shorthand           = $shorthand;
		$this->options             = $options;
		$this->permissions         = $permissions;
		$this->post_type           = $post_type;
		$this->content_transformer = $content_transformer;
		$this->auth_state_manager  = $auth_state_manager;
		$this->file_system         = $file_system;
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
		if ( ! StoryId::is_valid( $story_id ) ) {
			wp_die(
				esc_html__( 'Shorthand returned a story identifier that could not be recognised.', 'the-shorthand-editor' ),
				esc_html__( 'Error linking post to story.', 'the-shorthand-editor' )
			);
		}

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

		$this->sweep_story_pulls( $post_id, $request_nonce );

		$this->set_story_update_error( $post_id );
		$this->set_story_update_progress( $post_id, new StorySyncProgress( 0, 'Requesting story from Shorthand' ) );

		$story_id = get_post_meta( $post_id, 'story_id', true );
		if ( ! $story_id ) {
			return new WP_Error( 'pretty', 'Post does not have a Shorthand story associated with it' );
		}

		$destination_path = $this->get_default_story_bundle_path( $post_id, $story_id );
		if ( null === $destination_path ) {
			return $this->get_invalid_story_id_error( (string) $story_id );
		}

		$download_url = $this->post_download_request( $story_id );

		if ( is_wp_error( $download_url ) ) {
			return $download_url;
		}

		$storage_path = "{$destination_path}_{$request_nonce}";

		$this->file_system->make_dir( $storage_path );

		$this->record_story_pull( $post_id, $request_nonce, $storage_path, 0 );

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
		$file_path = $this->get_download_chunk_file_path( $args->files, $args->storage_path );

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

		$this->record_story_pull( $args->post_id, $args->request_nonce, $args->storage_path, $args->files );

		$progress = $args->get_progress_percent( 90 );

		$this->set_story_update_progress( $args->post_id, new StorySyncProgress( $progress, 'Saving story to WordPress' ) );

		return new WP_Error( 'retry', 'Request further file data', 0 );
	}

	private function get_download_chunk_file_path( int $chunk_number, string $storage_path ): string {
		return "{$storage_path}/file-{$chunk_number}.part";
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
		$this->delete_pull_chunks( $args->storage_path, $args->files );
		$this->forget_story_pull( $args->post_id, $args->request_nonce );
	}

	/**
	 * Removes the chunk files of one pull, and the directory holding them.
	 *
	 * The chunks are named, not listed: a pull directory cannot be enumerated
	 * on a remote uploads directory.
	 *
	 * @param string $storage_path Pull directory.
	 * @param int    $files        Number of chunks downloaded into it.
	 */
	private function delete_pull_chunks( string $storage_path, int $files ): void {
		for ( $idx = 0; $idx < $files; $idx++ ) {
			$this->file_system->delete_file( $this->get_download_chunk_file_path( $idx, $storage_path ) );
		}

		$this->file_system->delete_dir( $storage_path );
	}

	/**
	 * In-flight pulls for a post, keyed by request nonce.
	 *
	 * @param int $post_id Post being published.
	 * @return array<string, array{path: string, files: int}>
	 */
	private function get_story_pulls( int $post_id ): array {
		$pulls = get_post_meta( $post_id, 'story_pulls', true );

		return is_array( $pulls ) ? $pulls : array();
	}

	/**
	 * Notes the pull directory of one request, and how many chunks it holds.
	 *
	 * A superseded pull returns without cleaning up, so the record is what
	 * lets a later publish find its chunks.
	 *
	 * @param int    $post_id      Post being published.
	 * @param string $nonce        Request nonce identifying the pull.
	 * @param string $storage_path Pull directory.
	 * @param int    $files        Number of chunks downloaded so far.
	 */
	private function record_story_pull( int $post_id, string $nonce, string $storage_path, int $files ): void {
		$pulls = $this->get_story_pulls( $post_id );

		$pulls[ $nonce ] = array(
			'path'  => $storage_path,
			'files' => $files,
		);

		update_post_meta( $post_id, 'story_pulls', $pulls );
	}

	/**
	 * Drops the record of one pull.
	 *
	 * @param int    $post_id Post being published.
	 * @param string $nonce   Request nonce identifying the pull.
	 */
	private function forget_story_pull( int $post_id, string $nonce ): void {
		$pulls = $this->get_story_pulls( $post_id );

		unset( $pulls[ $nonce ] );

		if ( array() === $pulls ) {
			delete_post_meta( $post_id, 'story_pulls' );
			return;
		}

		update_post_meta( $post_id, 'story_pulls', $pulls );
	}

	/**
	 * Cleans up every pull except the one starting now.
	 *
	 * @param int    $post_id Post being published.
	 * @param string $nonce   Request nonce of the pull starting now.
	 */
	private function sweep_story_pulls( int $post_id, string $nonce ): void {
		foreach ( $this->get_story_pulls( $post_id ) as $stale_nonce => $pull ) {
			if ( (string) $stale_nonce === $nonce || ! isset( $pull['path'] ) ) {
				continue;
			}

			$this->delete_pull_chunks( (string) $pull['path'], isset( $pull['files'] ) ? (int) $pull['files'] : 0 );
		}

		delete_post_meta( $post_id, 'story_pulls' );
	}

	public function pull_story_completed( StoryUpdateTask $args ): ?\WP_Error {
		$staging_path  = $this->file_system->make_temp_dir( "sh_pull_{$args->request_nonce}_" );
		$zip_file_path = "{$staging_path}/archive.zip";

		$chunk_paths = array();
		for ( $idx = 0; $idx < $args->files; $idx++ ) {
			$chunk_paths[] = $this->get_download_chunk_file_path( $idx, $args->storage_path );
		}

		if ( ! $this->file_system->join_pieces( $chunk_paths, $zip_file_path ) ) {
			$this->file_system->delete_temp_dir( $staging_path );
			return new WP_Error( 'file', 'Failed to assemble story download.', $zip_file_path );
		}

		$story = $this->extract_story_content( $zip_file_path, $args->post_id, $args->story_id, $staging_path );

		$this->file_system->delete_temp_dir( $staging_path );

		if ( is_wp_error( $story ) ) {
			return $story;
		}

		$this->set_story_update_progress( $args->post_id );

		$this->set_post_story_version( $args->post_id, (int) $args->content_version );

		$this->pull_story_cleanup( $args );
		return null;
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

	/**
	 * Unpacks a story archive into the bundle directory, and stores its documents.
	 *
	 * @param string     $zip_file     Assembled archive, inside the staging directory.
	 * @param int|string $post_id      Post the story belongs to.
	 * @param string     $story_id     Shorthand story ID.
	 * @param string     $staging_path Staging directory for this pull.
	 */
	public function extract_story_content( $zip_file, $post_id, $story_id, string $staging_path ): ?\WP_Error {
		$bundle_url  = $this->get_story_bundle_url( $post_id, $story_id );
		$bundle_path = $this->get_story_bundle_path( $post_id, $story_id );

		if ( null === $bundle_path || null === $bundle_url ) {
			return $this->get_invalid_story_id_error( (string) $story_id );
		}

		$stored_manifest = $this->get_story_manifest( $post_id );

		$story = $this->unzip_story( $zip_file, $bundle_path, $staging_path, $stored_manifest );
		if ( is_wp_error( $story ) ) {
			$error = new WP_Error( 'story', 'Story being published', $story_id );
			$error->merge_from( $story );
			return $error;
		}

		$head    = $story['head'];
		$article = $story['article'];

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

		/* Last, so that a failure above leaves the previous manifest in place. */
		update_post_meta( $post_id, 'story_manifest', $story['manifest'] );

		return null;
	}

	/**
	 * Manifest of the last successful publish, or an empty array.
	 *
	 * @param int|string $post_id Post the bundle belongs to.
	 * @return array<string, array{size: int, crc: int}>
	 */
	public function get_story_manifest( $post_id ): array {
		return BundleManifest::from_meta( get_post_meta( absint( $post_id ), 'story_manifest', true ) );
	}

	/**
	 * Unpacks an archive into the bundle directory, and reads its two documents.
	 *
	 * With staging on, the archive is unpacked under the staging directory and
	 * then copied into the bundle directory. `ZipArchive::extractTo()` uses
	 * native syscalls, so it can only target a local path.
	 *
	 * @param string $zip_file        Assembled archive.
	 * @param string $story_path      Bundle directory.
	 * @param string $staging_path    Staging directory for this pull.
	 * @param array  $stored_manifest Manifest of the last successful publish.
	 * @return array{head: string, article: string, manifest: array}|\WP_Error
	 */
	private function unzip_story( $zip_file, $story_path, string $staging_path, array $stored_manifest ) {
		$zip = new ZipArchive();
		$ok  = $zip->open( $zip_file );
		if ( $ok !== true ) {
			$file_size = wp_filesize( $zip_file );
			$err       = new WP_Error( 'file', "Could not open story archive at {$zip_file}.", $zip_file );
			$err->add( 'file_size', "File size is {$file_size}.", $file_size );
			$err->add( 'zip', self::get_zip_error_message( $ok ), $ok );
			return $err;
		}

		$unpack_path = $this->options->is_staging_enabled() ? "{$staging_path}/unpacked" : $story_path;

		$this->file_system->make_dir( $unpack_path );

		$head     = $zip->getFromName( 'head.html' );
		$article  = $zip->getFromName( 'article.html' );
		$manifest = BundleManifest::from_archive( $zip );

		if ( ! $zip->extractTo( $unpack_path ) || ! $zip->close() ) {
			$file_size = wp_filesize( $zip_file );
			$err       = new WP_Error( 'file', "Could not extract story archive at {$zip_file}.", $zip_file );
			$err->add( 'file_size', "File size is {$file_size}.", $file_size );
			$err->add( 'zip', $zip->getStatusString(), $zip->status );
			return $err;
		}

		if ( $unpack_path !== $story_path ) {
			$copied = $this->file_system->copy_tree( $unpack_path, $story_path, $manifest, $stored_manifest );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		$stale = BundleManifest::removed( $stored_manifest, $manifest );

		if ( array() !== $stale ) {
			$this->file_system->delete_manifest( $story_path, $stale );
		}

		if ( ! $head ) {
			$head = '';
		}

		if ( ! $article ) {
			$article = '';
		}

		return array(
			'head'     => $head,
			'article'  => $article,
			'manifest' => $manifest,
		);
	}

	/**
	 * Public URL of the bundle directory, or null when the story ID is invalid.
	 *
	 * @param int|string $post_id  Post the bundle belongs to.
	 * @param string     $story_id Shorthand story ID.
	 */
	public function get_story_bundle_url( $post_id, $story_id ): ?string {
		if ( ! StoryId::is_valid( $story_id ) ) {
			return null;
		}

		$destination_url = wp_upload_dir()['baseurl'] . '/shorthand/' . absint( $post_id ) . '/' . $story_id;

		$destination_url = apply_filters( 'theshed_get_story_url', $destination_url );

		return $destination_url;
	}

	/**
	 * Path of the bundle directory, or null when the story ID is invalid.
	 *
	 * @param int|string $post_id  Post the bundle belongs to.
	 * @param string     $story_id Shorthand story ID.
	 */
	public function get_story_bundle_path( $post_id, $story_id ): ?string {
		return $this->get_default_story_bundle_path( $post_id, $story_id );
	}

	/**
	 * Builds the bundle directory path, or withholds it for an unusable story ID.
	 *
	 * @param int|string $post_id  Post the bundle belongs to.
	 * @param string     $story_id Shorthand story ID.
	 */
	private function get_default_story_bundle_path( $post_id, $story_id ): ?string {
		if ( ! StoryId::is_valid( $story_id ) ) {
			return null;
		}

		$destination_path = wp_upload_dir()['basedir'] . '/shorthand/' . absint( $post_id ) . '/' . $story_id;

		return $destination_path;
	}

	/**
	 * The error returned wherever a stored story ID cannot be used as a path.
	 *
	 * @param string $story_id The rejected story ID, carried as error data.
	 */
	private function get_invalid_story_id_error( string $story_id ): WP_Error {
		$error = new WP_Error( 'pretty', __( 'This post is linked to a story that Shorthand does not recognise. Please reconnect the post to its story.', 'the-shorthand-editor' ) );
		$error->add( 'story_id', 'Stored Shorthand story ID is not a valid path segment.', $story_id );
		return $error;
	}

	/**
	 * Removes a published bundle.
	 *
	 * A host that can enumerate deletes the tree outright. One that cannot
	 * refuses, and the manifest names what to unlink instead. The `{post_id}`
	 * parent is left alone: it cannot be listed, so it cannot be known to be
	 * empty.
	 *
	 * @param int    $post_id  Post the bundle belongs to.
	 * @param string $story_id Shorthand story ID.
	 */
	public function delete_story_bundle( int $post_id, string $story_id ): void {
		$bundle_path = $this->get_story_bundle_path( $post_id, $story_id );
		if ( null === $bundle_path ) {
			return;
		}

		if ( ! $this->file_system->delete_tree( $bundle_path ) ) {
			$this->file_system->delete_manifest( $bundle_path, $this->get_story_manifest( $post_id ) );
		}

		delete_post_meta( $post_id, 'story_manifest' );
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
