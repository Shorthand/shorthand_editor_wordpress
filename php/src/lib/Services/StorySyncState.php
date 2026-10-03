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
	 * Warning left by the last successful publish.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $publishing_warning;

	/**
	 * The story's state, as the editor reads it.
	 *
	 * @param int|null                                   $live_version       Version last published, if any.
	 * @param array<int, array<string, mixed>>|null      $publishing_error   Why the last publish failed.
	 * @param \Shorthand\Services\StorySyncProgress|null $progress           Publish in progress.
	 * @param array<int, array<string, mixed>>|null      $publishing_warning What the last publish warned of.
	 */
	public function __construct( ?int $live_version, ?array $publishing_error, ?StorySyncProgress $progress, ?array $publishing_warning = null ) {
		$this->live_version       = $live_version;
		$this->publishing_error   = $publishing_error;
		$this->progress           = $progress;
		$this->publishing_warning = $publishing_warning;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$state = array(
			'errors'      => array(
				'publishing' => $this->publishing_error,
			),
			'warnings'    => array(
				'publishing' => $this->publishing_warning,
			),
			'liveVersion' => $this->live_version,
		);

		if ( null !== $this->progress ) {
			$state['progress'] = $this->progress->to_array();
		}

		return $state;
	}
}
