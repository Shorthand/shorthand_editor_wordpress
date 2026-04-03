<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Plugin;
use Shorthand\Plugin\Dependencies;
use Shorthand\Services\StoryKses;
use Shorthand\Tests\WordPressTestCase;

final class PluginBootstrapTest extends WordPressTestCase {

	public function test_plugin_init_boots_dependencies_and_story_kses(): void {
		$dependencies = $this->createMock( Dependencies::class );
		$story_kses   = $this->createMock( StoryKses::class );
		$options      = new TestOptions();
		$post_type    = new TestPostType();
		$cron         = new TestCron();

		$dependencies
			->expects( $this->once() )
			->method( 'boot' );

		$dependencies
			->expects( $this->once() )
			->method( 'get_options' )
			->willReturn( $options );

		$dependencies
			->expects( $this->once() )
			->method( 'get_version' )
			->willReturn( new \Shorthand\Core\Version() );

		$dependencies
			->expects( $this->once() )
			->method( 'get_post_type' )
			->willReturn( $post_type );

		$dependencies
			->expects( $this->once() )
			->method( 'get_cron' )
			->willReturn( $cron );

		$story_kses
			->expects( $this->once() )
			->method( 'init' );

		$plugin = new Plugin( $dependencies, $story_kses );

		$plugin->init();
	}
}
