<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;
use Shorthand\Plugin\Dependencies;

use WP_Error;

class Cron {

	/**
	 * Event that runs one step of a story pull.
	 */
	const PULL_HOOK = 'shorthand_pull_story_cron';

	/**
	 * Recurring event that checks pulls in flight.
	 */
	const WATCH_HOOK = 'shorthand_pull_watch';

	/**
	 * Name of the `cron_schedules` interval the watch runs on.
	 */
	const WATCH_INTERVAL = 'shorthand_five_minutes';

	/**
	 * Seconds a step that finds the pull locked waits before it tries again.
	 */
	const LOCKED_RETRY_DELAY = 30;

	/**
	 * Seconds the step lock lasts for the step that assembles the download.
	 *
	 * Joining, unzipping and copying a large bundle can outlast a download
	 * step's limit. The step saves a heartbeat between phases, so the pull
	 * watch does not resume it while it runs.
	 */
	const COMPLETION_LOCK_LIMIT = 900;

	/**
	 * @var \Shorthand\Plugin\Dependencies
	 */
	private $dependencies;

	public function __construct( Dependencies $dependencies ) {
		$this->dependencies = $dependencies;
	}

	public function init() {
		$loader = new Loader();
		$loader->add_action( self::PULL_HOOK, $this, 'pull_story_cron', 10, 1 );
		$loader->add_action( self::WATCH_HOOK, $this, 'pull_watch_cron', 10, 0 );
		$loader->add_filter( 'cron_schedules', $this, 'add_cron_schedules', 10, 1 );
		if ( defined( 'THESHED_FIX_CRON_URL' ) && THESHED_FIX_CRON_URL ) {
			$loader->add_filter( 'site_url', $this, 'site_url_filter', 10, 4 );
		}
		$loader->register();
	}

	public function site_url_filter( $url, $path, $scheme, $blog_id ) {
		if ( $path === 'wp-cron.php' ) {
			$post_api = $this->dependencies->get_post_api();
			$url      = $post_api->fix_api_url( $url );
		}
		return $url;
	}

	/**
	 * Starts a background operation to pull the Shorthand story for a given post
	 *
	 * @param mixed $post_id
	 * @return void
	 */
	public function schedule_pull_story( int $post_id ) {
		$post_api = $this->dependencies->get_post_api();
		$args     = $post_api->pull_story_begin( $post_id );

		if ( ! is_wp_error( $args ) ) {
			$json = wp_json_encode( $args );

			/* Stored first, so the watch can find the step even if scheduling fails. */
			$post_api->set_story_update_task( $post_id, $json );

			$args = wp_schedule_single_event( time(), self::PULL_HOOK, array( $json ) );

			if ( true === $args ) {
				$this->schedule_pull_watch();
			}
		}

		if ( is_wp_error( $args ) ) {
			$post_api->set_story_update_error( $post_id, $args );
		}

		return $args;
	}

	/**
	 * Runs one step of a pull, holding the post's step lock.
	 *
	 * The lock is held until the next step is stored and scheduled, so a
	 * waiting duplicate finds the newer step and exits. A step that finds
	 * the lock held tries again later, while it is still current.
	 *
	 * @param string $args_json Step, as stored and scheduled.
	 */
	public function pull_story_cron( string $args_json ): void {
		$args = StoryUpdateTask::from_json( $args_json );
		if ( ! $args ) {
			return;
		}

		$post_api = $this->dependencies->get_post_api();
		$lock     = $this->dependencies->get_pull_lock();
		$limit    = $args->is_download_complete() ? self::COMPLETION_LOCK_LIMIT : $post_api->get_step_limit();
		$expiry   = $lock->acquire( $args->post_id, $limit );

		if ( null === $expiry ) {
			if ( $post_api->is_current_step( $args ) ) {
				$this->queue_step( $args_json, time() + self::LOCKED_RETRY_DELAY );
			}
			return;
		}

		try {
			/* The step that held the lock may have written the pull's meta since this request read it. */
			wp_cache_delete( $args->post_id, 'post_meta' );

			if ( ! $post_api->is_current_step( $args ) ) {
				return;
			}

			$result = $post_api->pull_story_cron( $args );

			if ( $result === null ) {
				return;
			}

			if ( $result->get_error_code() === 'retry' ) {
				$delay  = $result->get_error_data( 'retry' );
				$result = $this->schedule_step( $args, time() + $delay );
			}

			if ( is_wp_error( $result ) ) {
				$post_api->pull_story_failed( $args, $result );
			}
		} finally {
			$lock->release( $args->post_id, $expiry );
		}
	}

	/**
	 * Stores the next step of a pull, then schedules it.
	 *
	 * @param StoryUpdateTask $args      Next step.
	 * @param int             $timestamp When it should run.
	 * @return true|\WP_Error
	 */
	private function schedule_step( StoryUpdateTask $args, int $timestamp ) {
		$json = wp_json_encode( $args );

		/* Stored first, so the watch can find the step even if scheduling fails. */
		$this->dependencies->get_post_api()->set_story_update_task( $args->post_id, $json );

		return $this->queue_step( $json, $timestamp );
	}

	/**
	 * Schedules a step without storing it.
	 *
	 * A failed schedule is read back once, then retried once. Another cron
	 * run may have saved an identical `cron` option, and `update_option()`
	 * reports an unchanged value as a failure.
	 *
	 * A step that found the pull locked uses it directly. Storing would
	 * overwrite a newer step the lock holder stored, and reset the watch's
	 * resume count.
	 *
	 * @param string $json      Exact argument of the event.
	 * @param int    $timestamp When it should run.
	 * @return true|\WP_Error
	 */
	private function queue_step( string $json, int $timestamp ) {
		$result = wp_schedule_single_event( $timestamp, self::PULL_HOOK, array( $json ), true );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		wp_cache_delete( 'alloptions', 'options' );
		if ( false !== wp_next_scheduled( self::PULL_HOOK, array( $json ) ) ) {
			return true;
		}

		return wp_schedule_single_event( $timestamp, self::PULL_HOOK, array( $json ), true );
	}

	/**
	 * Schedules the recurring watch when it is missing.
	 *
	 * Plugin updates do not run activation, so the first pull after an
	 * update schedules it.
	 */
	private function schedule_pull_watch(): void {
		if ( false === wp_next_scheduled( self::WATCH_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::WATCH_INTERVAL, self::WATCH_HOOK );
		}
	}

	/**
	 * Adds the watch's five-minute interval.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Intervals known to WP-Cron.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::WATCH_INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'the-shorthand-editor' ),
		);

		return $schedules;
	}

	/**
	 * Checks every pull in flight. Runs on the recurring watch event.
	 */
	public function pull_watch_cron(): void {
		$this->dependencies->get_pull_watch()->sweep();
	}
}
