<?php

namespace Shorthand\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Shorthand\Core\Loader;

/**
 * Keeps the site's last measured download speed, for the next pull.
 *
 * A pull sizes its parts from the speed it measures. A new pull has measured
 * nothing yet, so it starts from this stored value instead of a probe.
 *
 * The option holds `array( 'bytes_per_second' => int, 'measured_at' => int )`,
 * and is not autoloaded. A write that fails, as cron writes can, only means
 * the next pull probes again.
 */
class StoryPullSpeed {

	/**
	 * Option name.
	 */
	const OPTION = 'shorthand_pull_speed';

	/**
	 * Age past which a stored speed is ignored, in seconds.
	 */
	const MAX_AGE = 7 * 24 * 60 * 60;

	/**
	 * Registers the Site Health display of the stored speed.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function init( Loader $loader ): void {
		$loader->add_filter( 'debug_information', $this, 'add_debug_information' );
	}

	/**
	 * Stored speed, when it is fresh enough to use.
	 *
	 * @return int Bytes per second; 0 when none is stored, it is older than `MAX_AGE`, or it is not a positive number.
	 */
	public function get(): int {
		$stored = $this->read();
		if ( null === $stored || time() - $stored['measured_at'] >= self::MAX_AGE ) {
			return 0;
		}

		return $stored['bytes_per_second'];
	}

	/**
	 * Stores a measured speed, with the time it was measured.
	 *
	 * @param int $bytes_per_second Measured speed; a value below 1 is not stored.
	 */
	public function record( int $bytes_per_second ): void {
		if ( $bytes_per_second < 1 ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'bytes_per_second' => $bytes_per_second,
				'measured_at'      => time(),
			),
			false
		);
	}

	/**
	 * Adds the stored speed to Tools → Site Health → Info.
	 *
	 * @param array $info Site Health sections.
	 * @return array
	 */
	public function add_debug_information( $info ) {
		$stored = $this->read();

		if ( null === $stored ) {
			$value = __( 'Not measured yet', 'the-shorthand-editor' );
			$debug = 'none';
		} else {
			/* translators: 1: download speed, such as "2 MB". 2: date and time it was measured. */
			$value = sprintf( __( '%1$s per second, measured %2$s', 'the-shorthand-editor' ), size_format( $stored['bytes_per_second'] ), wp_date( 'Y-m-d H:i', $stored['measured_at'] ) );
			$debug = "{$stored['bytes_per_second']} B/s at {$stored['measured_at']}";
		}

		$info['the-shorthand-editor'] = array(
			'label'  => __( 'The Shorthand Editor', 'the-shorthand-editor' ),
			'fields' => array(
				'pull_speed' => array(
					'label' => __( 'Story download speed', 'the-shorthand-editor' ),
					'value' => $value,
					'debug' => $debug,
				),
			),
		);

		return $info;
	}

	/**
	 * Stored value, when it is well formed.
	 *
	 * @return array{bytes_per_second: int, measured_at: int}|null
	 */
	private function read(): ?array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! isset( $stored['bytes_per_second'], $stored['measured_at'] ) || ! is_int( $stored['bytes_per_second'] ) || ! is_int( $stored['measured_at'] ) || $stored['bytes_per_second'] < 1 ) {
			return null;
		}

		return array(
			'bytes_per_second' => $stored['bytes_per_second'],
			'measured_at'      => $stored['measured_at'],
		);
	}
}
