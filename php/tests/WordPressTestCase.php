<?php

declare(strict_types=1);

namespace Shorthand\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Shorthand\Services\Files\FileSystem;

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

	/**
	 * @return mixed
	 */
	protected function getPrivateProperty( object $object, string $property_name ) {
		$reflection = new ReflectionClass( $object );
		$property   = $reflection->getProperty( $property_name );

		if ( method_exists( $property, 'setAccessible' ) ) {
			$property->setAccessible( true );
		}

		return $property->getValue( $object );
	}

	/**
	 * @param array<int, mixed> $arguments
	 * @return mixed
	 */
	protected function callPrivateMethod( object $object, string $method_name, array $arguments = array() ) {
		$reflection = new ReflectionClass( $object );
		$method     = $reflection->getMethod( $method_name );

		if ( method_exists( $method, 'setAccessible' ) ) {
			$method->setAccessible( true );
		}

		return $method->invokeArgs( $object, $arguments );
	}

	/**
	 * Returns the file system boot to the state of a fresh request.
	 *
	 * `FileSystem` remembers a successful boot in a static, which outlives a
	 * test. Anything exercising a failed boot has to forget it first.
	 */
	protected function forgetFileSystemBoot(): void {
		$forget = \Closure::bind(
			static function (): void {
				FileSystem::$booted = false;
			},
			null,
			FileSystem::class
		);

		$forget();

		unset( $GLOBALS['wp_filesystem'] );
	}
}
