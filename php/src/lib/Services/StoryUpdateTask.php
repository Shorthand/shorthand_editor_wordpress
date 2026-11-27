<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryUpdateTask {

	/**
	 * Default size of one downloaded range, in MiB.
	 *
	 * Sites can change it, in bytes, through the `shorthand_pull_chunk_size` filter.
	 */
	const CHUNK_SIZE_MB = 25;

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

	public function ensure_chunk_window(): void {
		if ( 0 === $this->end ) {
			$this->end = self::get_chunk_size();
		}
	}

	/**
	 * Size of one downloaded range, in bytes.
	 *
	 * A pull keeps the window it started with, so a change applies to the
	 * next pull.
	 *
	 * @return int At least 1 byte; a filtered value below that falls back to the default.
	 */
	public static function get_chunk_size(): int {
		$default = 1024 * 1024 * self::CHUNK_SIZE_MB;

		/**
		 * Filters the size of one range of the story download, in bytes.
		 *
		 * Each range is one HTTP request with a 30 second timeout, so a
		 * smaller value suits a slow link.
		 *
		 * @param int $size Bytes per range. Default 25 MiB.
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

	public function mark_chunk_downloaded(): void {
		++$this->files;

		$chunk_size  = max( $this->end - $this->start, 1 );
		$this->start = $this->end;
		$this->end   = $this->start + $chunk_size;
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

		return $task;
	}
}
