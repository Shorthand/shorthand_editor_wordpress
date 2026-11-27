<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Core\Loader;
use Shorthand\Services\StoryPullSpeed;
use Shorthand\Tests\WordPressTestCase;

/**
 * The download speed kept between pulls.
 */
final class StoryPullSpeedTest extends WordPressTestCase {

	public function test_a_fresh_speed_is_used(): void {
		$this->store( 875000, time() - 3600 );

		$this->assertSame( 875000, ( new StoryPullSpeed() )->get() );
	}

	public function test_no_stored_speed_reads_as_unknown(): void {
		$this->assertSame( 0, ( new StoryPullSpeed() )->get() );
	}

	public function test_a_speed_older_than_7_days_is_ignored(): void {
		$this->store( 875000, time() - StoryPullSpeed::MAX_AGE - 1 );

		$this->assertSame( 0, ( new StoryPullSpeed() )->get() );
	}

	/**
	 * Stored values that must read as no speed.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function junk_values(): array {
		return array(
			'zero speed'     => array(
				array(
					'bytes_per_second' => 0,
					'measured_at'      => time(),
				),
			),
			'string speed'   => array(
				array(
					'bytes_per_second' => '875000',
					'measured_at'      => time(),
				),
			),
			'missing time'   => array( array( 'bytes_per_second' => 875000 ) ),
			'not an array'   => array( 875000 ),
			'negative speed' => array(
				array(
					'bytes_per_second' => -1,
					'measured_at'      => time(),
				),
			),
		);
	}

	/**
	 * A stored value that is not well formed reads as no speed.
	 *
	 * @dataProvider junk_values
	 *
	 * @param mixed $value Stored option value.
	 */
	public function test_a_junk_value_is_ignored( $value ): void {
		update_option( StoryPullSpeed::OPTION, $value );

		$this->assertSame( 0, ( new StoryPullSpeed() )->get() );
	}

	public function test_record_stores_the_speed_and_the_time(): void {
		( new StoryPullSpeed() )->record( 250000 );

		$stored = get_option( StoryPullSpeed::OPTION );
		$this->assertSame( 250000, $stored['bytes_per_second'] );
		$this->assertEqualsWithDelta( time(), $stored['measured_at'], 2 );
		$this->assertSame( 250000, ( new StoryPullSpeed() )->get() );
	}

	public function test_record_ignores_a_speed_below_one(): void {
		( new StoryPullSpeed() )->record( 0 );

		$this->assertFalse( get_option( StoryPullSpeed::OPTION ) );
	}

	public function test_site_health_shows_the_stored_speed(): void {
		$this->store( 250000, 1700000000 );
		$speed = new StoryPullSpeed();
		$speed->init( new Loader() );

		$info = $speed->add_debug_information( array() );

		$field = $info['the-shorthand-editor']['fields']['pull_speed'];
		$this->assertSame( '250000 B per second, measured 2023-11-14 22:13', $field['value'] );
		$this->assertSame( '250000 B/s at 1700000000', $field['debug'] );
	}

	public function test_site_health_says_when_no_speed_is_stored(): void {
		$info = ( new StoryPullSpeed() )->add_debug_information( array( 'wp-core' => array() ) );

		$this->assertArrayHasKey( 'wp-core', $info );
		$this->assertSame( 'Not measured yet', $info['the-shorthand-editor']['fields']['pull_speed']['value'] );
	}

	/**
	 * Stores a speed as a past pull would have.
	 *
	 * @param int $bytes_per_second Speed.
	 * @param int $measured_at      Time it was measured.
	 */
	private function store( int $bytes_per_second, int $measured_at ): void {
		update_option(
			StoryPullSpeed::OPTION,
			array(
				'bytes_per_second' => $bytes_per_second,
				'measured_at'      => $measured_at,
			)
		);
	}
}
