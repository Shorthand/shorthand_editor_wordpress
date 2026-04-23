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

	public function __construct( float $percent, ?string $status ) {
		$this->percent = $percent;
		$this->status  = $status;
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

		return new self(
			(float) $value['percent'],
			isset( $value['status'] ) ? $value['status'] : null
		);
	}

	/**
	 * @return array{percent: float, status: string|null}
	 */
	public function to_array(): array {
		return array(
			'percent' => $this->percent,
			'status'  => $this->status,
		);
	}
}
