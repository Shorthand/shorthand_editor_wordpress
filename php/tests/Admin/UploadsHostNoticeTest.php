<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\UploadsHostNotice;
use Shorthand\Tests\WordPressTestCase;

/**
 * Vendor identity appears here and nowhere else, and only ever as a message.
 */
final class UploadsHostNoticeTest extends WordPressTestCase {

	public function test_a_local_uploads_directory_needs_no_explanation(): void {
		tests_wp_set_upload_dir( '/var/www/html/wp-content/uploads', 'https://example.test/uploads' );

		$this->assertSame( '', UploadsHostNotice::get_message() );
	}

	public function test_the_vip_file_system_is_named(): void {
		tests_wp_set_upload_dir( 'vip://wp-content/uploads', 'https://example.test/uploads' );

		$this->assertStringContainsString( 'WordPress VIP', UploadsHostNotice::get_message() );
	}

	public function test_wp_stateless_is_named(): void {
		tests_wp_set_upload_dir( 'gs://example-bucket/uploads', 'https://example.test/uploads' );

		$this->assertStringContainsString( 'WP Stateless', UploadsHostNotice::get_message() );
	}

	public function test_wp_offload_media_is_named(): void {
		tests_wp_set_upload_dir( 's3://example-bucket/uploads', 'https://example.test/uploads' );

		$this->assertStringContainsString( 'WP Offload Media', UploadsHostNotice::get_message() );
	}

	/**
	 * A host with no named case still gets a sentence.
	 */
	public function test_an_unknown_scheme_is_reported_by_name(): void {
		tests_wp_set_upload_dir( 'azure://example-container/uploads', 'https://example.test/uploads' );

		$this->assertStringContainsString( 'azure://', UploadsHostNotice::get_message() );
	}

	public function test_the_scheme_is_matched_without_regard_to_case(): void {
		tests_wp_set_upload_dir( 'VIP://wp-content/uploads', 'https://example.test/uploads' );

		$this->assertStringContainsString( 'WordPress VIP', UploadsHostNotice::get_message() );
	}
}
