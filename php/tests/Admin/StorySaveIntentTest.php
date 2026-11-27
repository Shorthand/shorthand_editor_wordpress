<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\StorySaveIntent;
use Shorthand\Tests\WordPressTestCase;

/**
 * Tests which requests carry editor intent for a story save.
 */
final class StorySaveIntentTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		\tests_wp_set_nonce( 'edit-nonce', StorySaveIntent::edit_action( 7 ) );
		\tests_wp_set_nonce( 'list-nonce', StorySaveIntent::LIST_ACTION );
	}

	protected function tearDown(): void {
		unset( $_POST['action'], $_POST['data'], $_POST['post_ID'], $_POST[ StorySaveIntent::FIELD ], $_REQUEST['bulk_edit'], $_REQUEST['post'], $_REQUEST[ StorySaveIntent::FIELD ] );
		parent::tearDown();
	}

	public function test_the_edit_screen_claims_its_post_once(): void {
		$this->post( 'editpost', '7', 'edit-nonce' );
		$intent = new StorySaveIntent();

		$this->assertTrue( $intent->claim( 7 ) );
		$this->assertFalse( $intent->claim( 7 ), 'A nested save finds the intent used' );
		$this->assertFalse( $intent->expired( 7 ) );
	}

	public function test_quick_edit_claims_its_post(): void {
		$this->post( 'inline-save', '7', 'list-nonce' );

		$this->assertTrue( ( new StorySaveIntent() )->claim( 7 ) );
	}

	public function test_bulk_edit_claims_each_post_it_names(): void {
		$_REQUEST['bulk_edit']              = 'Update';
		$_REQUEST['post']                   = array( '6', '7' );
		$_REQUEST[ StorySaveIntent::FIELD ] = 'list-nonce';
		$intent                             = new StorySaveIntent();

		$this->assertTrue( $intent->claim( 6 ) );
		$this->assertTrue( $intent->claim( 7 ) );
		$this->assertFalse( $intent->claim( 8 ) );
	}

	public function test_a_request_for_another_post_claims_nothing_and_is_not_expired(): void {
		$this->post( 'editpost', '8', 'edit-nonce' );
		$intent = new StorySaveIntent();

		$this->assertFalse( $intent->claim( 7 ) );
		$this->assertFalse( $intent->expired( 7 ) );
	}

	public function test_an_edit_screen_with_a_bad_or_missing_nonce_is_expired(): void {
		$this->post( 'editpost', '7', 'old-nonce' );
		$intent = new StorySaveIntent();

		$this->assertFalse( $intent->claim( 7 ) );
		$this->assertTrue( $intent->expired( 7 ) );

		unset( $_POST[ StorySaveIntent::FIELD ] );
		$missing = new StorySaveIntent();

		$this->assertFalse( $missing->claim( 7 ) );
		$this->assertTrue( $missing->expired( 7 ) );
	}

	public function test_a_quick_edit_with_a_bad_nonce_is_not_expired(): void {
		$this->post( 'inline-save', '7', 'edit-nonce' );
		$intent = new StorySaveIntent();

		$this->assertFalse( $intent->claim( 7 ) );
		$this->assertFalse( $intent->expired( 7 ) );
	}

	public function test_a_request_from_elsewhere_claims_nothing(): void {
		$this->post( 'heartbeat', '7', 'edit-nonce' );

		$this->assertFalse( ( new StorySaveIntent() )->claim( 7 ) );
	}

	public function test_a_new_post_with_no_id_claims_nothing(): void {
		$this->post( 'editpost', '0', 'edit-nonce' );

		$this->assertFalse( ( new StorySaveIntent() )->claim( 0 ) );
	}

	/**
	 * Makes the request a POST from an editor screen.
	 *
	 * @param string $action  Value of `action`.
	 * @param string $post_id Value of `post_ID`.
	 * @param string $nonce   Value of the intent field.
	 */
	private function post( string $action, string $post_id, string $nonce ): void {
		$_POST['action']                 = $action;
		$_POST['post_ID']                = $post_id;
		$_POST[ StorySaveIntent::FIELD ] = $nonce;
	}

	public function test_an_autosave_of_the_post_with_its_update_nonce_is_an_autosave(): void {
		\tests_wp_set_nonce( 'autosave-nonce', 'update-post_7' );
		$_POST['action'] = 'heartbeat';
		$_POST['data']   = array(
			'wp_autosave' => array(
				'post_id'  => '7',
				'_wpnonce' => 'autosave-nonce',
			),
		);

		$intent = new StorySaveIntent();

		$this->assertTrue( $intent->is_autosave( 7 ) );
		$this->assertFalse( $intent->is_autosave( 8 ) );
		$this->assertFalse( $intent->claim( 7 ), 'An autosave never claims full intent' );
	}

	public function test_an_autosave_with_a_bad_nonce_is_not_an_autosave(): void {
		\tests_wp_set_nonce( 'autosave-nonce', 'update-post_7' );
		$_POST['action'] = 'heartbeat';
		$_POST['data']   = array(
			'wp_autosave' => array(
				'post_id'  => '7',
				'_wpnonce' => 'other-nonce',
			),
		);

		$this->assertFalse( ( new StorySaveIntent() )->is_autosave( 7 ) );
	}
}
