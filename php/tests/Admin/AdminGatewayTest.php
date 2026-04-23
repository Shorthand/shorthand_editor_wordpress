<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\AdminGateway;
use Shorthand\Tests\WordPressTestCase;

final class AdminGatewayTest extends WordPressTestCase {

	public function test_settings_page_url_uses_the_registered_slug(): void {
		$gateway = new AdminGateway( 'theshed-settings' );

		$url = $gateway->get_settings_page_url();

		$this->assertStringContainsString( 'page=theshed-settings', $url );
		$this->assertStringNotContainsString( 'shorthand-settings', $url );
	}

	public function test_all_stories_url_includes_post_type(): void {
		$gateway = new AdminGateway( 'theshed-settings' );

		$url = $gateway->get_all_stories_url( 'tse_story' );

		$this->assertStringContainsString( 'post_type=tse_story', $url );
	}
}
