<?php

declare(strict_types=1);

namespace Shorthand\Tests\Services;

use Shorthand\Services\StoryId;
use Shorthand\Tests\WordPressTestCase;

final class StoryIdTest extends WordPressTestCase {

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function rejected_story_ids(): array {
		return array(
			'parent directory'      => array( '../../etc/passwd' ),
			'traversal segment'     => array( 'abc/../def' ),
			'absolute path'         => array( '/etc/passwd' ),
			'windows path'          => array( 'c:\\windows\\system32' ),
			'null byte'             => array( "abc\0def" ),
			'trailing null byte'    => array( "abc\0" ),
			'forward slash'         => array( 'abc/def' ),
			'backslash'             => array( 'abc\\def' ),
			'dot'                   => array( 'abc.def' ),
			'hyphen'                => array( 'abc-def' ),
			'underscore'            => array( 'abc_def' ),
			'space'                 => array( 'abc def' ),
			'newline'               => array( "abc\ndef" ),
			'stream wrapper scheme' => array( 'vip://abc' ),
			'empty string'          => array( '' ),
			'null'                  => array( null ),
			'integer'               => array( 123 ),
			'array'                 => array( array( 'abc' ) ),
		);
	}

	/**
	 * @dataProvider rejected_story_ids
	 * @param mixed $story_id
	 */
	public function test_rejects_anything_that_is_not_an_alphanumeric_token( $story_id ): void {
		$this->assertFalse( StoryId::is_valid( $story_id ) );
		$this->assertSame( '', StoryId::sanitize( $story_id ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function accepted_story_ids(): array {
		return array(
			'lower case'  => array( 'abcdef' ),
			'upper case'  => array( 'ABCDEF' ),
			'mixed case'  => array( 'aBcDeF' ),
			'digits only' => array( '1234567890' ),
			'mixed'       => array( 'a1B2c3D4' ),
			'single char' => array( 'a' ),
		);
	}

	/**
	 * @dataProvider accepted_story_ids
	 */
	public function test_accepts_an_alphanumeric_token( string $story_id ): void {
		$this->assertTrue( StoryId::is_valid( $story_id ) );
	}

	/**
	 * Two story IDs differing only in case are distinct stories, so sanitizing
	 * must not fold them together the way `sanitize_key()` would.
	 */
	public function test_preserves_the_case_of_a_valid_story_id(): void {
		$this->assertSame( 'aBcDeF', StoryId::sanitize( 'aBcDeF' ) );
		$this->assertSame( 'ABCDEF', StoryId::sanitize( 'ABCDEF' ) );
	}
}
