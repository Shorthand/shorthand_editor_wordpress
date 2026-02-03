<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StoryUpdateTask {

	const CHUNK_SIZE_MB = 5;

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
