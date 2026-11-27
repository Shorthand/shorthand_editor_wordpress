<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Notices a story pull that stopped advancing, from a later request.
 *
 * A step can die without a trace: after MySQL "Commands out of sync", every
 * write in that request fails, including the next step's schedule and any
 * error the step tries to store. Each step therefore saves a heartbeat with
 * its progress, and stores its successor before scheduling it. The editor
 * poll, saves with intent and a recurring event read them back here.
 */
class StoryPullWatch {

	/**
	 * Seconds without a heartbeat before a pull counts as stalled.
	 */
	const STALE_AFTER = 300;

	/**
	 * Seconds without a heartbeat before a pull is given up, even with its step still queued.
	 */
	const GIVE_UP_AFTER = 1800;

	/**
	 * Posts the recurring event checks per run.
	 */
	const SWEEP_LIMIT = 20;

	const IDLE    = 'idle';
	const RUNNING = 'running';
	const RESUMED = 'resumed';
	const DEAD    = 'dead';

	/**
	 * Reads and writes the pull's post meta.
	 *
	 * @var \Shorthand\Services\PostAPI
	 */
	private $post_api;

	/**
	 * Story post type, for the sweep's query.
	 *
	 * @var string
	 */
	private $post_type;

	public function __construct( PostAPI $post_api, string $post_type ) {
		$this->post_api  = $post_api;
		$this->post_type = $post_type;
	}

	/**
	 * Checks the pull of one post, resuming or failing it when it stalled.
	 *
	 * @param int  $post_id Post being published.
	 * @param bool $saving  True when called from inside a save of the post, which sets the status itself.
	 * @return string One of `idle`, `running`, `resumed` or `dead`.
	 */
	public function check( int $post_id, bool $saving = false ): string {
		$progress = $this->post_api->get_story_update_progress( $post_id );
		if ( null === $progress ) {
			return self::IDLE;
		}

		if ( null !== $this->post_api->get_story_update_error( $post_id ) ) {
			/* A failed pull keeps its progress. Dropping it lets the sweep stop finding the post. */
			$this->post_api->set_story_update_progress( $post_id );
			return self::IDLE;
		}

		/* Written by 1.0.11. Stamping it gives a pull in flight during the upgrade its full grace. */
		$updated_at = $progress->get_updated_at();
		if ( null === $updated_at ) {
			$this->post_api->set_story_update_progress( $post_id, $progress->touch() );
			return self::RUNNING;
		}

		$age = time() - $updated_at;
		if ( $age < self::STALE_AFTER ) {
			return self::RUNNING;
		}

		/* On a quiet site a queued step waits for traffic. That is not a fault. */
		$task = $this->post_api->get_story_update_task( $post_id );
		if ( null !== $task && $age < self::GIVE_UP_AFTER && false !== wp_next_scheduled( Cron::PULL_HOOK, array( $task['json'] ) ) ) {
			return self::RUNNING;
		}

		if ( null !== $task && 0 === $task['attempts'] && $this->resume( $post_id, $task['json'], $progress ) ) {
			return self::RESUMED;
		}

		$this->post_api->abandon_pull( $post_id, $this->get_stalled_error( $age ), ! $saving );
		return self::DEAD;
	}

	/**
	 * Checks every post with a pull in flight. Runs on `shorthand_pull_watch`.
	 *
	 * The event unschedules itself once no pull is left; the next pull
	 * schedules it again.
	 */
	public function sweep(): void {
		$post_ids = get_posts(
			array(
				'post_type'   => $this->post_type,
				'post_status' => 'any',
				'numberposts' => self::SWEEP_LIMIT,
				'fields'      => 'ids',
				'meta_key'    => 'story_update_state', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		if ( array() === $post_ids ) {
			wp_clear_scheduled_hook( Cron::WATCH_HOOK );
			return;
		}

		foreach ( $post_ids as $post_id ) {
			$this->check( (int) $post_id );
		}
	}

	/**
	 * Schedules the stored step once more.
	 *
	 * The next step stores a new task with no attempts, so each step of a
	 * pull that advances can be resumed once.
	 *
	 * @param int               $post_id  Post being published.
	 * @param string            $json     Exact argument of the lost event.
	 * @param StorySyncProgress $progress Last heartbeat.
	 * @return bool Whether the step was scheduled.
	 */
	private function resume( int $post_id, string $json, StorySyncProgress $progress ): bool {
		$this->post_api->set_story_update_task( $post_id, $json, 1 );
		$this->post_api->set_story_update_progress( $post_id, $progress->touch() );

		return true === wp_schedule_single_event( time(), Cron::PULL_HOOK, array( $json ), true );
	}

	/**
	 * The error a stalled pull is failed with. The editor shows its `pretty` message.
	 *
	 * @param int $age Seconds since the last heartbeat.
	 */
	private function get_stalled_error( int $age ): WP_Error {
		$error = new WP_Error( 'stalled', "The story download made no progress for {$age} seconds.", $age );
		$error->add( 'pretty', __( 'The story download stopped before it finished. Click Update to try again.', 'the-shorthand-editor' ) );
		return $error;
	}
}
