<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorySyncProgress {

	/**
	 * @var float
	 */
	private $percent;

	/**
	 * @var string|null
	 */
	private $status;

	/**
	 * Unix time of the write, the pull's heartbeat.
	 *
	 * Null only for progress read back from a version that did not record it.
	 *
	 * @var int|null
	 */
	private $updated_at;

	public function __construct( float $percent, ?string $status ) {
		$this->percent    = $percent;
		$this->status     = $status;
		$this->updated_at = time();
	}

	/**
	 * @param mixed $value
	 */
	public static function from_meta_value( $value ): ?self {
		if ( ! is_array( $value ) || ! isset( $value['percent'] ) || ! is_numeric( $value['percent'] ) ) {
			return null;
		}

		if ( isset( $value['status'] ) && ! is_string( $value['status'] ) && null !== $value['status'] ) {
			return null;
		}

		$progress = new self(
			(float) $value['percent'],
			isset( $value['status'] ) ? $value['status'] : null
		);

		$progress->updated_at = isset( $value['updated_at'] ) && is_numeric( $value['updated_at'] ) ? (int) $value['updated_at'] : null;

		return $progress;
	}

	/**
	 * Unix time this progress was written, or null when its writer did not record it.
	 */
	public function get_updated_at(): ?int {
		return $this->updated_at;
	}

	/**
	 * The same progress, stamped with the current time.
	 */
	public function touch(): self {
		return new self( $this->percent, $this->status );
	}

	/**
	 * @return array{percent: float, status: string|null, updated_at?: int}
	 */
	public function to_array(): array {
		$value = array(
			'percent' => $this->percent,
			'status'  => $this->status,
		);

		if ( null !== $this->updated_at ) {
			$value['updated_at'] = $this->updated_at;
		}

		return $value;
	}
}
