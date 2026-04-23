<?php

declare(strict_types=1);

namespace Shorthand\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

abstract class WordPressTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\tests_wp_reset_state();
	}

	/**
	 * @param class-string $class_name
	 */
	protected function instantiateWithoutConstructor( string $class_name ): object {
		$reflection = new ReflectionClass( $class_name );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * @param mixed $value
	 */
	protected function setPrivateProperty( object $object, string $property_name, $value ): void {
		$reflection = new ReflectionClass( $object );
		$property   = $reflection->getProperty( $property_name );

		if ( method_exists( $property, 'setAccessible' ) ) {
			$property->setAccessible( true );
		}

		$property->setValue( $object, $value );
	}
}
