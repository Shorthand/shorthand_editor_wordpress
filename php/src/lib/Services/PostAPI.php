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

	const HTTP_TOO_MANY_REQUESTS = 429;

	/**
	 * Default time one pull step spends downloading, in seconds.
	 *
	 * A step starts no part it expects to end past it, so a step stays
	 * under a host that ends requests at 20 s of wall time. Sites can
	 * change it through the `theshed_pull_step_budget` filter.
	 */
	const STEP_BUDGET = 15;

	/**
	 * Limit on one range request, in seconds.
	 */
	const CHUNK_TIMEOUT = 30;

	/**
	 * Time closing one chunk file can take, in seconds.
	 *
	 * On VIP, closing the file uploads it to the Files API, and the request
	 * timeout does not count that time.
	 */
	const CHUNK_CLOSE_TIME = 60;

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
	/**
	 * Derives the plain text stored on the post.
	 *
	 * @var \Shorthand\Services\StoryTextExtractor
	 */
	private $text_extractor;
	/**
	 * Applies the story cover as the featured image.
	 *
	 * @var \Shorthand\Services\StoryCover
	 */
	private $story_cover;
	/**
	 * Guards the plugin's own write against the publish hooks it fires.
	 *
	 * @var bool
	 */
	private $storing_text = false;
	/**
	 * Stores the download speed between pulls.
	 *
	 * @var \Shorthand\Services\StoryPullSpeed
	 */
	private $pull_speed;
	/**
	 * Returns the current time in seconds, as a float.
	 *
	 * @var callable
	 */
	private $clock;
	/**
	 * Bytes of good parts this step, since the last timeout.
	 *
	 * @var int
	 */
	private $step_bytes = 0;
	/**
	 * Seconds spent in part requests this step, since the last timeout.
	 *
	 * @var float
	 */
	private $step_seconds = 0.0;
	/**
	 * Tells whether the running step still owns its pull; null outside a step.
	 *
	 * @var callable|null
	 */
	private $guard = null;

	/**
	 * Wires the services a pull needs.
	 *
	 * @param Shorthand               $shorthand           Shorthand API client.
	 * @param Options                 $options             Plugin settings.
	 * @param Permissions             $permissions         Capability checks.
	 * @param string                  $post_type           Story post type.
	 * @param AuthStateManager        $auth_state_manager  Shorthand connection state.
	 * @param StoryContentTransformer $content_transformer Rewrites story documents.
	 * @param FileSystemService       $file_system         Uploads and staging directories.
	 * @param StoryTextExtractor      $text_extractor      Derives the post's plain text.
	 * @param StoryCover              $story_cover         Applies the featured image.
	 * @param StoryPullSpeed          $pull_speed          Stores the download speed between pulls.
	 * @param callable|null           $clock               Returns the current time in seconds. Defaults to `microtime( true )`.
	 */
	public function __construct( Shorthand $shorthand, Options $options, Permissions $permissions, string $post_type, AuthStateManager $auth_state_manager, StoryContentTransformer $content_transformer, FileSystemService $file_system, StoryTextExtractor $text_extractor, StoryCover $story_cover, StoryPullSpeed $pull_speed, ?callable $clock = null ) {
		$this->shorthand           = $shorthand;
		$this->options             = $options;
		$this->permissions         = $permissions;
		$this->post_type           = $post_type;
		$this->content_transformer = $content_transformer;
		$this->auth_state_manager  = $auth_state_manager;
		$this->file_system         = $file_system;
		$this->text_extractor      = $text_extractor;
		$this->story_cover         = $story_cover;
		$this->pull_speed          = $pull_speed;
		$this->clock               = $clock ?? static function (): float {
			return microtime( true );
		};
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

	/**
	 * Reads the notice the last save from the editor left, as an error list.
	 *
	 * A notice explains why that save fetched nothing, such as a failed
	 * version check. Unlike `story_update_error`, it does not mean a pull failed.
	 *
	 * @param int $post_id Story post.
	 * @return array<int, array<string, mixed>>|null
	 */
	public function get_story_update_notice( int $post_id ): ?array {
		$notice = get_post_meta( $post_id, 'story_update_notice', true );
		return is_array( $notice ) && array() !== $notice ? $notice : null;
	}

	/**
	 * Stores the notice of a save from the editor, or clears it.
	 *
	 * @param int            $post_id Story post.
	 * @param \WP_Error|null $notice  Notice to store; null clears it.
	 */
	public function set_story_update_notice( int $post_id, ?WP_Error $notice = null ): void {
		if ( null === $notice ) {
			delete_post_meta( $post_id, 'story_update_notice' );
			return;
		}

		update_post_meta( $post_id, 'story_update_notice', $this->get_wp_error_as_array( $notice ) );
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
	 * Saves a fresh heartbeat for a pull in flight.
	 *
	 * Does nothing when no pull is in flight, as when an archive is
	 * unpacked outside a pull.
	 *
	 * @param int    $post_id Post being published.
	 * @param float  $percent Progress shown in the editor.
	 * @param string $status  Step shown in the editor.
	 */
	private function record_heartbeat( int $post_id, float $percent, string $status ): void {
		if ( null === $this->get_story_update_progress( $post_id ) ) {
			return;
		}

		$this->set_story_update_progress( $post_id, new StorySyncProgress( $percent, $status ) );
	}

	/**
	 * The pull step waiting to run, as stored before it was scheduled.
	 *
	 * `json` is the exact argument of the `shorthand_pull_story_cron` event,
	 * so `wp_next_scheduled()` can tell whether the step is still queued.
	 * `attempts` counts how often `StoryPullWatch` resumed it.
	 *
	 * @param int $post_id Post being published.
	 * @return array{json: string, attempts: int}|null
	 */
	public function get_story_update_task( int $post_id ): ?array {
		$task = get_post_meta( $post_id, 'story_update_task', true );
		if ( ! is_array( $task ) || ! isset( $task['json'] ) || ! is_string( $task['json'] ) ) {
			return null;
		}

		return array(
			'json'     => $task['json'],
			'attempts' => isset( $task['attempts'] ) ? (int) $task['attempts'] : 0,
		);
	}

	/**
	 * Stores the pull step about to be scheduled.
	 *
	 * @param int    $post_id  Post being published.
	 * @param string $json     Exact argument of the `shorthand_pull_story_cron` event.
	 * @param int    $attempts How often the watch has resumed this step.
	 */
	public function set_story_update_task( int $post_id, string $json, int $attempts = 0 ): void {
		/* Meta writes unslash their value, and the JSON must survive byte for byte. */
		update_post_meta(
			$post_id,
			'story_update_task',
			array(
				'json'     => wp_slash( $json ),
				'attempts' => $attempts,
			)
		);
	}

	/**
	 * Drops the stored step, unless it belongs to a newer pull.
	 *
	 * @param StoryUpdateTask $args Step of the pull that ended.
	 */
	private function forget_story_update_task( StoryUpdateTask $args ): void {
		$task   = $this->get_story_update_task( $args->post_id );
		$stored = null === $task ? null : StoryUpdateTask::from_json( $task['json'] );

		if ( null !== $stored && $stored->request_nonce !== $args->request_nonce ) {
			return;
		}

		delete_post_meta( $args->post_id, 'story_update_task' );
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

		$destination_path = $this->get_story_bundle_path( $post_id, $story_id );
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
			return $this->get_error_from_payload( $story_id, $payload, $status_code );
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
			/* A step that lost the pull must not write its 0% over the newer pull's progress. */
			if ( ! $this->owns_pull() ) {
				return $this->get_superseded_error();
			}

			$this->set_story_update_progress( $args->post_id, new StorySyncProgress( 0, __( 'Waiting for Shorthand to prepare the story', 'the-shorthand-editor' ) ) );
			return new WP_Error( 'retry', 'File download not ready.', 5 ); /* 5 second retry */
		}

		if ( self::HTTP_TOO_MANY_REQUESTS === $status_code ) {
			return $this->get_rate_limited_error( $args->story_id, $payload );
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

	/**
	 * Resolve the status a post should return to when a publish is interrupted.
	 *
	 * A post that has never been published has no restorable public status
	 * (`auto-draft`, or no status at all), so it falls back to `draft`.
	 *
	 * @param string|false|null $prior_status Status the post held before publishing began.
	 * @return string
	 */
	public static function get_restore_status( $prior_status ): string {
		if ( empty( $prior_status ) || 'auto-draft' === $prior_status ) {
			return 'draft';
		}
		return $prior_status;
	}

	public function fix_api_url( string $url ): string {
		if ( strncmp( $url, 'https://localhost', strlen( 'https://localhost' ) ) === 0 ) {
			return str_replace( 'https://localhost', 'https://host.docker.internal', $url );
		}
		return $url;
	}

	/**
	 * Runs one step of a pull.
	 *
	 * A download step fetches parts until the archive is complete or the
	 * step budget is spent. The step after the last part assembles and
	 * unpacks the archive, with a fresh budget.
	 *
	 * Each part is sized from the download speed measured so far. The first
	 * step of a download starts from the speed stored by the last download,
	 * and the step stores what it measured for the next one.
	 *
	 * The guard is asked before each write. When it answers false, a newer
	 * pull or another run of the step took over, and the step stops.
	 *
	 * @param StoryUpdateTask $args  Step to run; advanced as parts land.
	 * @param callable|null   $guard Returns whether the step still owns the pull. Defaults to a check of the pull nonce.
	 * @return null|\WP_Error Null when the pull ended or was superseded; a `retry` error when another step is needed.
	 */
	public function pull_story_cron( StoryUpdateTask $args, ?callable $guard = null ) {
		/* An earlier event in the same cron run may have broken the connection; the nonce read below must not fail with it. */
		$this->reconnect_database();

		$started = $this->now();

		if ( ! $this->check_pull_story_status( $args ) ) {
			/* terminate this request immediately if there is a new request in flight */
			return null;
		}

		$this->guard = $guard ?? function () use ( $args ): bool {
			wp_cache_delete( $args->post_id, 'post_meta' );
			return $this->check_pull_story_status( $args );
		};

		try {
			$result = $this->run_step( $args, $started );
		} finally {
			$this->guard = null;
		}

		return null !== $result && FileSystemService::SUPERSEDED === $result->get_error_code() ? null : $result;
	}

	/**
	 * Runs the parts of one step, or the completion step.
	 *
	 * @param StoryUpdateTask $args    Step to run.
	 * @param float           $started When the step began.
	 * @return null|\WP_Error
	 */
	private function run_step( StoryUpdateTask $args, float $started ): ?WP_Error {
		$res = $this->check_file_url( $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( 0 === $args->end && 0 === $args->speed ) {
			$args->speed = $this->pull_speed->get();
		}

		$args->ensure_chunk_window();

		if ( $args->is_download_complete() ) {
			/* this request has been completed */
			return $this->pull_story_completed( $args );
		}

		$budget             = $this->get_step_budget();
		$this->step_bytes   = 0;
		$this->step_seconds = 0.0;

		/* At least one part per step, so a budget of 0 still makes progress. */
		do {
			$result = $this->pull_story_chunk( $args );
		} while ( null === $result && ! $args->is_download_complete() && $this->now() - $started + $this->get_part_seconds( $args ) < $budget );

		/* A step that lost the pull writes nothing more, not even the speed. */
		if ( null !== $result && FileSystemService::SUPERSEDED === $result->get_error_code() ) {
			return $result;
		}

		$speed = $this->get_step_speed();
		if ( $speed > 0 ) {
			$this->pull_speed->record( $speed );
		}

		return $result ?? new WP_Error( 'retry', 'Request further file data', 0 );
	}

	/**
	 * Speed of the step's good parts so far, in bytes per second.
	 *
	 * @return int 0 when nothing was timed yet.
	 */
	private function get_step_speed(): int {
		return $this->step_seconds > 0 ? (int) floor( $this->step_bytes / $this->step_seconds ) : 0;
	}

	/**
	 * Time the next part should take at the measured speed, in seconds.
	 *
	 * @param StoryUpdateTask $args Pull in progress, with its next window set.
	 * @return float 0 when the speed is unknown.
	 */
	private function get_part_seconds( StoryUpdateTask $args ): float {
		return $args->speed > 0 ? $args->get_expected_chunk_length() / $args->speed : 0.0;
	}

	/**
	 * Time one pull step may spend downloading, in seconds.
	 *
	 * The step starts no part it expects to end past the budget, from the
	 * measured speed. A part that is slower than expected, or that times
	 * out and is retried, can still take the step past it.
	 *
	 * @return float At most half of `max_execution_time` when PHP sets one.
	 */
	public function get_step_budget(): float {
		/**
		 * Filters the time one pull step spends downloading, in seconds.
		 *
		 * A step always downloads at least one range. 0 restores one range
		 * per step.
		 *
		 * @param int|float $budget Seconds. Default 15.
		 */
		$budget = max( (float) apply_filters( 'theshed_pull_step_budget', self::STEP_BUDGET ), 0.0 );

		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = min( $budget, $limit / 2 );
		}

		return $budget;
	}

	/**
	 * Longest time one download step can run, in seconds.
	 *
	 * The step lock expires after it. A step can overrun the budget by one
	 * range fetched twice, and each try can wait for the request timeout and
	 * the file close.
	 *
	 * @return int
	 */
	public function get_step_limit(): int {
		return (int) ceil( $this->get_step_budget() + 2 * ( self::CHUNK_TIMEOUT + self::CHUNK_CLOSE_TIME ) );
	}

	/**
	 * Whether a pull step should still run.
	 *
	 * A step is out of date when a newer pull started, when a later step of
	 * the same pull is already stored, or when the pull has ended. An event
	 * that ran twice, or ran late, is one of these.
	 *
	 * A pull started by 1.0.11 stored no step. It still runs while it has
	 * progress and no error.
	 *
	 * @param StoryUpdateTask $args Step about to run.
	 * @return bool
	 */
	public function is_current_step( StoryUpdateTask $args ): bool {
		if ( ! $this->check_pull_story_status( $args ) ) {
			return false;
		}

		$task   = $this->get_story_update_task( $args->post_id );
		$stored = null === $task ? null : StoryUpdateTask::from_json( $task['json'] );
		if ( null !== $stored && $stored->request_nonce === $args->request_nonce ) {
			return $stored->start <= $args->start;
		}

		/* An ended pull drops its stored step. A finished one also drops its progress; a failed one has an error. */
		return null !== $this->get_story_update_progress( $args->post_id ) && null === $this->get_story_update_error( $args->post_id );
	}

	/**
	 * Current time in seconds.
	 */
	private function now(): float {
		return (float) call_user_func( $this->clock );
	}

	/**
	 * Downloads the current part, checks it, and records it.
	 *
	 * A part is fetched once more when it comes back with the wrong start
	 * or length, or when its request times out. After a timeout the part is
	 * made smaller first. The request opens its file with `wb`, so the
	 * second attempt overwrites the first. Any other transport error is not
	 * retried.
	 *
	 * A good part updates the measured speed, which sizes the next part,
	 * and is stored as the pull's task, so a step that dies keeps it.
	 *
	 * @param StoryUpdateTask $args Pull in progress; advanced by one part on success.
	 * @return \WP_Error|null Null when the part landed.
	 */
	private function pull_story_chunk( StoryUpdateTask $args ): ?WP_Error {
		$file_path = $this->get_download_chunk_file_path( $args->files, $args->storage_path );
		$fault     = null;

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$began    = $this->now();
			$response = $this->request_chunk( $args, $file_path );

			$this->step_seconds += max( $this->now() - $began, 0.0 );

			$this->reconnect_database();

			/* A paused step can wake after another took over; it must not retry, delete or record over that step's file. */
			if ( ! $this->owns_pull() ) {
				return $this->get_superseded_error();
			}

			if ( is_wp_error( $response ) ) {
				$fault = $response;
				if ( 1 === $attempt && $this->is_timeout( $response ) ) {
					$this->shrink_after_timeout( $args, $file_path );
					continue;
				}
				break;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 206 !== $status_code ) {
				$fault = new WP_Error( 'status', "Pulling story chunk received HTTP status {$status_code}.", $status_code );
				break;
			}

			$fault = $this->check_chunk( $args, $response, $file_path );
			if ( null === $fault ) {
				break;
			}
		}

		if ( null !== $fault ) {
			/* Not yet counted, so the pull's cleanup would not name it. */
			$this->file_system->delete_file( $file_path );
			return $fault;
		}

		/* With no archive size, the window can be wider than what arrived. */
		$this->step_bytes += $args->size > 0 ? $args->get_expected_chunk_length() : (int) $this->file_system->get_file_size( $file_path );

		$args->mark_chunk_downloaded();

		$speed = $this->get_step_speed();
		if ( $speed > 0 ) {
			$args->speed = $speed;
		}

		$args->size_next_window( $args->speed );

		$this->record_story_pull( $args->post_id, $args->request_nonce, $args->storage_path, $args->files );

		/* A step the host kills keeps its parts; the watch resumes from here. */
		$this->set_story_update_task( $args->post_id, wp_json_encode( $args ) );

		$progress = $args->get_progress_percent( 90 );

		$this->set_story_update_progress( $args->post_id, new StorySyncProgress( $progress, 'Saving story to WordPress' ) );

		return null;
	}

	/**
	 * Whether a part request failed because it ran out of time.
	 *
	 * @param WP_Error $error Transport error from the request.
	 * @return bool
	 */
	private function is_timeout( WP_Error $error ): bool {
		if ( false !== strpos( $error->get_error_message(), 'cURL error 28' ) ) {
			return true;
		}

		return false !== stripos( $error->get_error_message( 'http_request_failed' ), 'timed out' );
	}

	/**
	 * Makes the current part smaller after its request timed out.
	 *
	 * The speed the request reached is read from what it wrote. When the
	 * partial file is missing, empty or cannot be measured, the speed is
	 * halved instead. The new part is at most half the one that timed out.
	 *
	 * The step's speed totals restart, so parts before the timeout do not
	 * grow the next part straight back.
	 *
	 * @param StoryUpdateTask $args      Pull in progress; its window is resized.
	 * @param string          $file_path Partial chunk file the request wrote.
	 */
	private function shrink_after_timeout( StoryUpdateTask $args, string $file_path ): void {
		$partial = (int) $this->file_system->get_file_size( $file_path );
		$reached = (int) floor( $partial / self::CHUNK_TIMEOUT );

		$args->speed = $reached > 0 ? $reached : intdiv( $args->speed, 2 );
		$args->shrink_window( $args->speed );

		$this->step_bytes   = 0;
		$this->step_seconds = 0.0;
	}

	/**
	 * Streams the current part of the archive into a chunk file.
	 *
	 * @param StoryUpdateTask $args      Pull in progress.
	 * @param string          $file_path Chunk file to write.
	 * @return array|\WP_Error The response, or the transport error.
	 */
	private function request_chunk( StoryUpdateTask $args, string $file_path ) {
		$start = $args->start;
		$end   = $args->end - 1;

		return $this->shorthand->shorthand_api_authed_request(
			$args->file_url,
			'GET',
			array(
				'stream'   => true,
				'filename' => $file_path,
				/* The 5 s default limits the whole transfer, which a part of up to 25 MiB can outlast. */
				'timeout'  => self::CHUNK_TIMEOUT,
				'headers'  => array(
					'Range' => "bytes={$start}-{$end}",
				),
			)
		);
	}

	/**
	 * Checks that a downloaded range starts where asked and is complete.
	 *
	 * The size is read through the file system service, so a chunk written
	 * to a remote uploads directory is measured where it landed.
	 *
	 * @param StoryUpdateTask $args      Pull in progress.
	 * @param array           $response  Response to the range request.
	 * @param string          $file_path Chunk file it was written to.
	 * @return \WP_Error|null Null when the range is good.
	 */
	private function check_chunk( StoryUpdateTask $args, array $response, string $file_path ): ?WP_Error {
		$range = wp_remote_retrieve_header( $response, 'Content-Range' );
		$range = is_array( $range ) ? (string) end( $range ) : (string) $range;

		if ( ! preg_match( '/^bytes (\d+)-\d+\/(?:\d+|\*)$/', trim( $range ), $match ) || (int) $match[1] !== $args->start ) {
			return $this->get_bad_chunk_error( $args, "Story chunk has Content-Range \"{$range}\"; it should start at byte {$args->start}." );
		}

		if ( $args->size <= 0 ) {
			return null;
		}

		/*
		 * A stream wrapper that cannot report a size gives null. The
		 * Content-Range check above still holds, and the assembly step fails
		 * on a missing chunk, so an unknown size does not fail the pull.
		 */
		$expected = $args->get_expected_chunk_length();
		$written  = $this->file_system->get_file_size( $file_path );
		if ( null !== $written && $written !== $expected ) {
			return $this->get_bad_chunk_error( $args, "Story chunk is {$written} bytes; it should be {$expected} bytes." );
		}

		return null;
	}

	/**
	 * Builds the error for a range that came back wrong twice.
	 *
	 * The failed pull puts the post back to its prior status, so the hint
	 * names the button the editor shows then.
	 *
	 * @param StoryUpdateTask $args   Pull in progress.
	 * @param string          $detail What was wrong with it.
	 * @return WP_Error
	 */
	private function get_bad_chunk_error( StoryUpdateTask $args, string $detail ): WP_Error {
		$message = sprintf(
			/* translators: %s: "Click Update to try again." or "Click Publish to try again." */
			__( 'The story download was incomplete. %s', 'the-shorthand-editor' ),
			self::get_retry_hint( self::get_restore_status( $args->prior_status ) )
		);

		$error = new WP_Error( 'chunk', $detail );
		$error->add( 'pretty', $message );
		return $error;
	}

	/**
	 * Whether a status shows the post as published, so the editor's button reads Update.
	 *
	 * @param string|false $status Post status.
	 * @return bool
	 */
	public static function is_publishing_status( $status ): bool {
		return in_array( $status, array( 'publish', 'future' ), true );
	}

	/**
	 * The last sentence of a failed pull's message, naming the button to click again.
	 *
	 * @param string|false $status Status the post returns to once the pull fails.
	 * @return string "Click Update to try again." for a published status, "Click Publish to try again." otherwise.
	 */
	public static function get_retry_hint( $status ): string {
		return self::is_publishing_status( $status )
			? __( 'Click Update to try again.', 'the-shorthand-editor' )
			: __( 'Click Publish to try again.', 'the-shorthand-editor' );
	}

	/**
	 * Whether the running step still owns its pull.
	 *
	 * Outside a step, as when an archive is unpacked on its own, there is
	 * nothing to lose.
	 *
	 * @return bool
	 */
	private function owns_pull(): bool {
		return null === $this->guard || (bool) call_user_func( $this->guard );
	}

	/**
	 * The error a step returns when a newer pull or another run of the step took over.
	 *
	 * @return WP_Error
	 */
	private function get_superseded_error(): WP_Error {
		return new WP_Error( FileSystemService::SUPERSEDED, 'A newer pull or another run of this step took over.' );
	}

	/**
	 * Reopens the database connection when it no longer answers.
	 *
	 * A long download can outlive the connection, and other code in the same
	 * cron run can leave it out of sync (MySQL error 2014). Either way, every
	 * later write in the request would fail. The probe is one `DO 1` query.
	 */
	private function reconnect_database(): void {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'check_connection' ) ) {
			$wpdb->check_connection( false );
		}
	}

	private function get_download_chunk_file_path( int $chunk_number, string $storage_path ): string {
		return "{$storage_path}/file-{$chunk_number}.part";
	}

	/**
	 * Records a failed pull, restores the post's prior status and cleans up.
	 *
	 * @param StoryUpdateTask $args           The pull that failed.
	 * @param WP_Error        $result         Why it failed.
	 * @param bool            $restore_status False when a save of the post is in progress and sets the status itself.
	 */
	public function pull_story_failed( StoryUpdateTask $args, WP_Error $result, bool $restore_status = true ): void {
		if ( ! $this->check_pull_story_status( $args ) ) {
			return;
		}

		$result->add( 'post', "Post ID {$args->post_id}.", $args->post_id );

		$this->set_story_update_error( $args->post_id, $result );

		// Restore the original post status; a first-time publish returns to draft. A trashed post stays in the trash.
		$prior_status = self::get_restore_status( $args->prior_status );
		$status       = get_post_status( $args->post_id );
		if ( $restore_status && 'trash' !== $status && $status !== $prior_status ) {
			/* Back to `future`, core's `_future_post_hook()` saves the whole event list; the step may be a minute old. */
			Cron::clear_cron_cache();
			wp_update_post(
				array(
					'ID'          => $args->post_id,
					'post_status' => $prior_status,
				)
			);
		}

		$this->pull_story_cleanup( $args );
	}

	private function pull_story_cleanup( StoryUpdateTask $args ): void {
		$this->delete_pull_chunks( $args->storage_path, $this->get_recorded_files( $args ) );
		$this->forget_story_pull( $args->post_id, $args->request_nonce );
		$this->forget_story_update_task( $args );
	}

	/**
	 * Fails a pull that stopped advancing, from a later request.
	 *
	 * The stored error unlocks the editor and makes the next Update start a
	 * new pull. The new nonce makes a step that was only late exit at once.
	 * With a stored step, the post returns to its prior status. A pull
	 * started by 1.0.11 stored none, so its status is left alone.
	 *
	 * A save of the post in progress passes `$restore_status` false: it
	 * sets the status itself, and a nested `wp_update_post()` would race it.
	 *
	 * @param int      $post_id        Post being published.
	 * @param WP_Error $error          Why the pull was given up.
	 * @param bool     $restore_status False when called from inside a save of the post.
	 */
	public function abandon_pull( int $post_id, WP_Error $error, bool $restore_status = true ): void {
		$task = $this->get_story_update_task( $post_id );
		$args = null === $task ? null : StoryUpdateTask::from_json( $task['json'] );

		/* `pull_story_failed()` does nothing once the nonce has moved on, so it runs first. */
		if ( null !== $args && $args->post_id === $post_id && $this->check_pull_story_status( $args ) ) {
			$this->delete_partial_chunk( $args->storage_path, $this->get_recorded_files( $args ) );
			$this->pull_story_failed( $args, $error, $restore_status );
			$this->reset_story_pull_request_nonce( $post_id );
			return;
		}

		$nonce = $this->reset_story_pull_request_nonce( $post_id );

		$this->set_story_update_error( $post_id, $error );
		$this->set_story_update_progress( $post_id );

		foreach ( $this->get_story_pulls( $post_id ) as $pull ) {
			if ( isset( $pull['path'] ) ) {
				$this->delete_partial_chunk( (string) $pull['path'], isset( $pull['files'] ) ? (int) $pull['files'] : 0 );
			}
		}

		$this->sweep_story_pulls( $post_id, $nonce );
		delete_post_meta( $post_id, 'story_update_task' );
	}

	/**
	 * Number of chunks a pull has left in its directory.
	 *
	 * A step downloads several chunks, and the stored task predates them.
	 * A resumed step starts again from that task. `story_pulls` keeps the
	 * highest count reached, so it can be ahead of `$args->files`.
	 *
	 * @param StoryUpdateTask $args Pull to count.
	 * @return int
	 */
	private function get_recorded_files( StoryUpdateTask $args ): int {
		$pulls = $this->get_story_pulls( $args->post_id );
		$files = isset( $pulls[ $args->request_nonce ]['files'] ) ? (int) $pulls[ $args->request_nonce ]['files'] : 0;

		return max( $args->files, $files );
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
	 * Removes the chunk a killed step was writing when it died.
	 *
	 * It is the one past the downloaded count, so `delete_pull_chunks()`
	 * does not name it.
	 *
	 * @param string $storage_path Pull directory.
	 * @param int    $files        Number of chunks fully downloaded into it.
	 */
	private function delete_partial_chunk( string $storage_path, int $files ): void {
		$this->file_system->delete_file( $this->get_download_chunk_file_path( $files, $storage_path ) );
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
	 * lets a later publish find its chunks. The count never goes down: a
	 * resumed step downloads again over chunks that are already there.
	 *
	 * @param int    $post_id      Post being published.
	 * @param string $nonce        Request nonce identifying the pull.
	 * @param string $storage_path Pull directory.
	 * @param int    $files        Number of chunks downloaded so far.
	 */
	private function record_story_pull( int $post_id, string $nonce, string $storage_path, int $files ): void {
		$pulls = $this->get_story_pulls( $post_id );

		if ( isset( $pulls[ $nonce ]['files'] ) ) {
			$files = max( $files, (int) $pulls[ $nonce ]['files'] );
		}

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

		$this->record_heartbeat( $args->post_id, 90, __( 'Assembling the story download', 'the-shorthand-editor' ) );

		if ( ! $this->owns_pull() ) {
			$this->file_system->delete_temp_dir( $staging_path );
			return $this->get_superseded_error();
		}

		if ( ! $this->file_system->join_pieces( $chunk_paths, $zip_file_path ) ) {
			$this->file_system->delete_temp_dir( $staging_path );
			return new WP_Error( 'file', 'Failed to assemble story download.', $zip_file_path );
		}

		$this->record_heartbeat( $args->post_id, 92, __( 'Unpacking the story', 'the-shorthand-editor' ) );

		$story = $this->owns_pull()
			? $this->extract_story_content( $zip_file_path, $args->post_id, $args->story_id, $staging_path, $args->request_nonce )
			: $this->get_superseded_error();

		$this->file_system->delete_temp_dir( $staging_path );

		if ( is_wp_error( $story ) ) {
			/* A step that lost the pull during the unpack reports that, not the unpack error it caused. */
			return in_array( FileSystemService::SUPERSEDED, $story->get_error_codes(), true ) ? $this->get_superseded_error() : $story;
		}

		if ( ! $this->owns_pull() ) {
			return $this->get_superseded_error();
		}

		$this->set_story_update_progress( $args->post_id );

		$this->set_post_story_version( $args->post_id, (int) $args->content_version );

		$this->pull_story_cleanup( $args );
		return null;
	}

	private function get_error_from_payload( $story_id, $payload, $status_code ): WP_Error {
		if ( self::HTTP_TOO_MANY_REQUESTS === $status_code ) {
			return $this->get_rate_limited_error( $story_id, $payload );
		}

		$error = new WP_Error( 'story', "The Shorthand story ID is {$story_id}.", $story_id );
		$error->add( 'status', "Received HTTP status {$status_code}.", $status_code );
		$this->add_error_params( $error, $payload );
		return $error;
	}

	/**
	 * Build the error served when the Shorthand API rate limits a story build.
	 *
	 * The API rejects story builds beyond the organisation's concurrency cap
	 * with a bare 429 and no Retry-After header, so the publish fails fast
	 * and the user chooses when to retry. The `pretty` entry is added before
	 * any payload params so the editor toolbar shows this message.
	 *
	 * @param string $story_id Shorthand story ID being published.
	 * @param mixed  $payload  Decoded JSON response body, if any.
	 * @return WP_Error
	 */
	private function get_rate_limited_error( string $story_id, $payload ): WP_Error {
		$error = new WP_Error( 'rate_limited', "The Shorthand story ID is {$story_id}.", $story_id );
		$error->add( 'pretty', __( 'Your Shorthand workspace is publishing too many stories at once. Wait a moment, then publish again.', 'the-shorthand-editor' ) );
		$error->add( 'status', 'Received HTTP status ' . self::HTTP_TOO_MANY_REQUESTS . '.', self::HTTP_TOO_MANY_REQUESTS );
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

	/**
	 * Unpacks a story archive into the bundle directory, and stores its documents.
	 *
	 * @param string     $zip_file     Assembled archive, inside the staging directory.
	 * @param int|string $post_id      Post the story belongs to.
	 * @param string     $story_id     Shorthand story ID.
	 * @param string     $staging_path Staging directory for this pull.
	 * @param string     $nonce        Request nonce of this pull, versioning the documents.
	 */
	public function extract_story_content( $zip_file, $post_id, $story_id, string $staging_path, string $nonce ): ?\WP_Error {
		$bundle_url  = $this->get_story_bundle_url( $post_id, $story_id );
		$bundle_path = $this->get_story_bundle_path( $post_id, $story_id );

		if ( null === $bundle_path || null === $bundle_url ) {
			return $this->get_invalid_story_id_error( (string) $story_id );
		}

		$stored_manifest = $this->get_story_manifest( $post_id );

		$story = $this->unzip_story( $zip_file, $bundle_path, $staging_path, $stored_manifest, $nonce, absint( $post_id ) );
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

		$article = apply_filters( 'theshed_post_process_body', $article, $bundle_path, $story['article_path'] );
		$head    = apply_filters( 'theshed_post_process_head', $head, $bundle_path, $story['head_path'] );

		/* A step that lost its pull leaves the newer pull's content in place. */
		if ( ! $this->owns_pull() ) {
			return $this->get_superseded_error();
		}

		update_post_meta( $post_id, 'story_head', wp_slash( $head ) );
		update_post_meta( $post_id, 'story_body', wp_slash( $article ) );

		$this->store_story_text( $post_id, $article );

		/* Never fails the publish; the featured image is a courtesy on top of the content. */
		$this->story_cover->sync( (int) $post_id, (string) $story_id );

		/* Last, so that a failure above leaves the previous manifest in place. */
		update_post_meta( $post_id, 'story_manifest', $story['manifest'] );

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

		/* For a scheduled post, the update runs core's `_future_post_hook()`, which saves the whole event list. */
		Cron::clear_cron_cache();

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

		/*
		 * Only ours is replaceable. Clearing the excerpt is an edit like any
		 * other, so an empty column with a non-empty meta stays empty.
		 */
		if ( $current !== $stored ) {
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
	 * @param string $nonce           Request nonce of this pull, versioning the documents.
	 * @param int    $post_id         Post the story belongs to, for the heartbeat.
	 * @return array{head: string, article: string, manifest: array, head_path: string, article_path: string}|\WP_Error
	 */
	private function unzip_story( $zip_file, $story_path, string $staging_path, array $stored_manifest, string $nonce, int $post_id ) {
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

		$docs_dir = $this->get_documents_dir( $nonce );

		if ( $unpack_path !== $story_path ) {
			if ( '' !== $docs_dir ) {
				$manifest = BundleManifest::relocate_documents( $manifest, $docs_dir );
			}

			$this->record_heartbeat( $post_id, 95, __( 'Copying the story files', 'the-shorthand-editor' ) );

			/* Asked before each file, so a long copy keeps the step lock, and a step that lost it stops. */
			$copied = $this->file_system->copy_tree(
				$unpack_path,
				$story_path,
				$manifest,
				$stored_manifest,
				function (): bool {
					return $this->owns_pull();
				}
			);

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}

			$manifest = $copied;
		} else {
			/* `extractTo()` wrote the bundle directly, so the archive layout stands. */
			$docs_dir = '';
		}

		$stale = BundleManifest::removed( $stored_manifest, $manifest );

		if ( array() !== $stale && ! $this->owns_pull() ) {
			return $this->get_superseded_error();
		}

		if ( array() !== $stale ) {
			$this->file_system->delete_manifest( $story_path, $stale );
		}

		if ( ! $head ) {
			$head = '';
		}

		if ( ! $article ) {
			$article = '';
		}

		$docs_path = '' === $docs_dir ? $story_path : "{$story_path}/{$docs_dir}";

		return array(
			'head'         => $head,
			'article'      => $article,
			'manifest'     => $manifest,
			'head_path'    => "{$docs_path}/head.html",
			'article_path' => "{$docs_path}/article.html",
		);
	}

	/**
	 * Bundle-relative directory holding the documents of one publish.
	 *
	 * The nonce is interpolated into a path, so it is validated the same way a
	 * story ID is. A nonce that fails leaves the documents at the root of the
	 * bundle, which is where they were before they were versioned.
	 *
	 * @param string $nonce Request nonce of this pull.
	 * @return string Directory relative to the bundle, or an empty string.
	 */
	private function get_documents_dir( string $nonce ): string {
		return StoryId::is_valid( $nonce ) ? "docs/{$nonce}" : '';
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
