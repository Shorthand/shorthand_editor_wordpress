<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryUpdateTask {

	/**
	 * Default largest size of one downloaded part, in MiB.
	 *
	 * Sites can change it, in bytes, through the `shorthand_pull_chunk_size` filter.
	 */
	const CHUNK_SIZE_MB = 25;

	/**
	 * Size of a part when the download speed is unknown, in bytes.
	 */
	const PROBE_SIZE = 5 * 1024 * 1024;

	/**
	 * Smallest part the speed can ask for, in bytes.
	 *
	 * The filtered cap still wins when it is smaller.
	 */
	const MIN_SIZE = 1024 * 1024;

	/**
	 * Time one part should take at the measured speed, in seconds.
	 *
	 * A third of `PostAPI::CHUNK_TIMEOUT`.
	 */
	const TARGET_SECONDS = 10;

	/**
	 * @var int
	 */
	public $post_id;
	/**
	 * @var string
	 */
	public $story_id;
	/**
	 * @var string
	 */
	public $request_nonce;
	/**
	 * @var string
	 */
	public $prior_status;
	/**
	 * @var string
	 */
	public $download_url;
	/**
	 * @var string
	 */
	public $storage_path;

	/**
	 * @var int|null
	 */
	public $content_version;

	/**
	 * @var string|null
	 */
	public $file_url = null;
	/**
	 * @var int
	 */
	public $size = 0;
	/**
	 * @var int
	 */
	public $start = 0;
	/**
	 * @var int
	 */
	public $end = 0;
	/**
	 * @var int
	 */
	public $files = 0;
	/**
	 * Measured download speed, in bytes per second; 0 when unknown.
	 *
	 * @var int
	 */
	public $speed = 0;

	public function __construct(
		int $post_id,
		string $story_id,
		string $request_nonce,
		string $prior_status,
		string $download_url,
		string $storage_path
	) {
		$this->post_id       = $post_id;
		$this->story_id      = $story_id;
		$this->request_nonce = $request_nonce;
		$this->prior_status  = $prior_status;
		$this->download_url  = $download_url;
		$this->storage_path  = $storage_path;
	}

	/**
	 * Sets the first window of a download, sized from the known speed.
	 *
	 * A window already set, by an earlier step or an older plugin, is kept.
	 */
	public function ensure_chunk_window(): void {
		if ( 0 === $this->end ) {
			$this->size_next_window( $this->speed );
		}
	}

	/**
	 * Sets the window of the next part from a download speed.
	 *
	 * The part should take `TARGET_SECONDS` at that speed. An unknown speed
	 * gives `PROBE_SIZE`. The width is at least `MIN_SIZE` and at most
	 * `get_chunk_size()`, and the window never passes the end of the archive.
	 *
	 * @param int $speed Bytes per second; 0 when unknown.
	 */
	public function size_next_window( int $speed ): void {
		$this->set_width( $speed > 0 ? $speed * self::TARGET_SECONDS : self::PROBE_SIZE );
	}

	/**
	 * Resizes the current window for a second try after a timeout.
	 *
	 * Sized from the speed as `size_next_window()` does, then held to at
	 * most half the window that timed out. `MIN_SIZE` and the cap still
	 * apply, so a window already at the floor keeps its size.
	 *
	 * @param int $speed Bytes per second; 0 when unknown.
	 */
	public function shrink_window( int $speed ): void {
		$width = $speed > 0 ? $speed * self::TARGET_SECONDS : self::PROBE_SIZE;

		$this->set_width( min( $width, intdiv( $this->end - $this->start, 2 ) ) );
	}

	/**
	 * Sets `end` from a part width, within the size limits.
	 *
	 * @param int $width Bytes wanted.
	 */
	private function set_width( int $width ): void {
		$width = min( self::get_chunk_size(), max( self::MIN_SIZE, $width ) );
		$end   = $this->start + $width;

		$this->end = $this->size > 0 ? min( $end, $this->size ) : $end;
	}

	/**
	 * Largest size of one downloaded part, in bytes.
	 *
	 * Parts are sized from the measured speed, up to this cap. A part
	 * already sized keeps its window, so a change applies from the next part.
	 *
	 * @return int At least 1 byte; a filtered value below that falls back to the default.
	 */
	public static function get_chunk_size(): int {
		$default = 1024 * 1024 * self::CHUNK_SIZE_MB;

		/**
		 * Filters the largest part of the story download, in bytes.
		 *
		 * Each part is one HTTP request with a 30 second timeout. Parts are
		 * sized from the measured download speed, up to this value. The 5 MiB
		 * first part and the 1 MiB floor also obey it.
		 *
		 * @param int $size Largest bytes per part. Default 25 MiB.
		 */
		$size = (int) apply_filters( 'shorthand_pull_chunk_size', $default );

		return $size > 0 ? $size : $default;
	}

	/**
	 * Number of bytes the current window should deliver.
	 *
	 * The last window may reach past the end of the archive, and the server
	 * then sends only what is left.
	 *
	 * @return int
	 */
	public function get_expected_chunk_length(): int {
		$end = $this->size > 0 ? min( $this->end, $this->size ) : $this->end;

		return max( $end - $this->start, 0 );
	}

	public function is_download_complete(): bool {
		return $this->start > 0 && $this->start >= $this->size;
	}

	/**
	 * Counts the part just downloaded and moves `start` past it.
	 *
	 * The window is left empty. The caller sets the next one with
	 * `size_next_window()`.
	 */
	public function mark_chunk_downloaded(): void {
		++$this->files;

		$this->start = $this->end;
	}

	public function get_progress_percent( int $cap = 90 ): int {
		return min( $cap, (int) round( ( $cap * $this->start ) / max( $this->size, 1 ) ) );
	}

	public static function from_json( string $json ): ?\Shorthand\Services\StoryUpdateTask {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Validate required fields
		if ( ! isset( $data['post_id'] ) || ! is_int( $data['post_id'] ) ) {
			return null;
		}
		if ( ! isset( $data['story_id'] ) || ! is_string( $data['story_id'] ) ) {
			return null;
		}
		if ( ! isset( $data['request_nonce'] ) || ! is_string( $data['request_nonce'] ) ) {
			return null;
		}
		if ( ! isset( $data['prior_status'] ) || ! is_string( $data['prior_status'] ) ) {
			return null;
		}
		if ( ! isset( $data['download_url'] ) || ! is_string( $data['download_url'] ) ) {
			return null;
		}
		if ( ! isset( $data['storage_path'] ) || ! is_string( $data['storage_path'] ) ) {
			return null;
		}

		// Create instance with required fields
		$task = new StoryUpdateTask(
			$data['post_id'],
			$data['story_id'],
			$data['request_nonce'],
			$data['prior_status'],
			$data['download_url'],
			$data['storage_path']
		);

		// Set optional fields if present
		if ( isset( $data['content_version'] ) && is_int( $data['content_version'] ) ) {
			$task->content_version = $data['content_version'];
		}
		if ( isset( $data['file_url'] ) && is_string( $data['file_url'] ) ) {
			$task->file_url = $data['file_url'];
		}
		if ( isset( $data['size'] ) && is_int( $data['size'] ) ) {
			$task->size = $data['size'];
		}
		if ( isset( $data['start'] ) && is_int( $data['start'] ) ) {
			$task->start = $data['start'];
		}
		if ( isset( $data['end'] ) && is_int( $data['end'] ) ) {
			$task->end = $data['end'];
		}
		if ( isset( $data['files'] ) && is_int( $data['files'] ) ) {
			$task->files = $data['files'];
		}
		if ( isset( $data['speed'] ) && is_int( $data['speed'] ) && $data['speed'] > 0 ) {
			$task->speed = $data['speed'];
		}

		return $task;
	}
}
