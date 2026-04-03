<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorySyncState {

	/**
	 * @var int|null
	 */
	private $live_version;

	/**
	 * @var array<int, array<string, mixed>>|null
	 */
	private $publishing_error;

	/**
	 * @var \Shorthand\Services\StorySyncProgress|null
	 */
	private $progress;

	/**
	 * @param array<int, array<string, mixed>>|null $publishing_error
	 */
	public function __construct( ?int $live_version, ?array $publishing_error = null, ?StorySyncProgress $progress = null ) {
		$this->live_version     = $live_version;
		$this->publishing_error = $publishing_error;
		$this->progress         = $progress;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$state = array(
			'errors'      => array(
				'publishing' => $this->publishing_error,
			),
			'liveVersion' => $this->live_version,
		);

		if ( null !== $this->progress ) {
			$state['progress'] = $this->progress->to_array();
		}

		return $state;
	}
}
