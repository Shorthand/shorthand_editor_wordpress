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
	 * @var string|null
	 */
	private $pending_title;

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
	 * @param string|null                                $pending_title      Title Shorthand has not received.
	 * @param array<int, array<string, mixed>>|null      $publishing_warning What the last publish warned of.
	 */
	public function __construct( ?int $live_version, ?array $publishing_error, ?StorySyncProgress $progress, ?string $pending_title = null, ?array $publishing_warning = null ) {
		$this->live_version       = $live_version;
		$this->publishing_error   = $publishing_error;
		$this->progress           = $progress;
		$this->pending_title      = $pending_title;
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

		if ( null !== $this->pending_title ) {
			$state['pendingTitle'] = $this->pending_title;
		}

		if ( null !== $this->progress ) {
			$state['progress'] = $this->progress->to_array();
		}

		return $state;
	}
}
