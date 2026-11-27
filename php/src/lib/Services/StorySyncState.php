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
	 * Why the last save from the editor fetched nothing, as an error list.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $notice;

	/**
	 * Collects the state the editor shows for one story.
	 *
	 * @param int|null                              $live_version     Content version of the fetched story.
	 * @param array<int, array<string, mixed>>|null $publishing_error Error of the last pull.
	 * @param StorySyncProgress|null                $progress         Progress of the pull in flight.
	 * @param string|null                           $pending_title    Title Shorthand has not yet received.
	 * @param array<int, array<string, mixed>>|null $notice           Why the last save from the editor fetched nothing.
	 */
	public function __construct( ?int $live_version, ?array $publishing_error, ?StorySyncProgress $progress, ?string $pending_title = null, ?array $notice = null ) {
		$this->live_version     = $live_version;
		$this->publishing_error = $publishing_error;
		$this->progress         = $progress;
		$this->pending_title    = $pending_title;
		$this->notice           = $notice;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		/* Each key is always sent; the client clears a key it receives as null. */
		$state = array(
			'errors'      => array(
				'publishing' => $this->publishing_error,
				'notice'     => $this->notice,
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
