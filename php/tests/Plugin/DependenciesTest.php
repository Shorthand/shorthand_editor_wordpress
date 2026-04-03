<?php

declare(strict_types=1);

namespace Shorthand\Tests\Plugin;

use Shorthand\Admin\AdminController;
use Shorthand\Core\Version;
use Shorthand\Plugin\Dependencies;
use Shorthand\Plugin\PostType;
use Shorthand\Plugin\Templates;
use Shorthand\Services\Cron;
use Shorthand\Services\Options;
use Shorthand\Services\Permissions;
use Shorthand\Services\PostAPI;
use Shorthand\Services\Shorthand;
use Shorthand\Services\TokenManager;
use Shorthand\Tests\WordPressTestCase;

final class DependenciesTest extends WordPressTestCase {

	public function test_constructor_does_not_boot_services(): void {
		$dependencies = new TestDependencies();

		$this->assertSame( 0, $dependencies->options_created );
		$this->assertSame( 0, $dependencies->token_manager_created );
		$this->assertSame( 0, $dependencies->post_type_created );
		$this->assertSame( 0, $dependencies->templates_created );
		$this->assertSame( 0, $dependencies->cron_created );
	}

	public function test_boot_initialises_each_service_once(): void {
		$dependencies = new TestDependencies();

		$dependencies->boot();
		$dependencies->boot();

		$this->assertSame( 1, $dependencies->options_created );
		$this->assertSame( 1, $dependencies->token_manager_created );
		$this->assertSame( 1, $dependencies->post_type_created );
		$this->assertSame( 1, $dependencies->templates_created );
		$this->assertSame( 1, $dependencies->cron_created );
		$this->assertTrue( $dependencies->test_options->init_called );
		$this->assertTrue( $dependencies->test_token_manager->init_called );
		$this->assertTrue( $dependencies->test_post_type->init_called );
		$this->assertTrue( $dependencies->test_templates->init_called );
		$this->assertTrue( $dependencies->test_cron->init_called );
	}
}

final class TestDependencies extends Dependencies {
	public $options_created = 0;
	public $token_manager_created = 0;
	public $post_type_created = 0;
	public $templates_created = 0;
	public $cron_created = 0;

	public $test_options;
	public $test_token_manager;
	public $test_post_type;
	public $test_templates;
	public $test_cron;

	protected function create_options( Version $version ): Options {
		++$this->options_created;
		$this->test_options = new TestOptions();
		return $this->test_options;
	}

	protected function create_shorthand( Options $options, Version $version ): Shorthand {
		return new TestShorthand();
	}

	protected function create_token_manager( Options $options, Shorthand $shorthand ): TokenManager {
		++$this->token_manager_created;
		$this->test_token_manager = new TestTokenManager();
		return $this->test_token_manager;
	}

	protected function create_post_type( string $permalink, Version $version ): PostType {
		++$this->post_type_created;
		$this->test_post_type = new TestPostType();
		return $this->test_post_type;
	}

	protected function create_templates( string $post_type, Options $options, Version $version ): Templates {
		++$this->templates_created;
		$this->test_templates = new TestTemplates();
		return $this->test_templates;
	}

	protected function create_cron( Dependencies $dependencies ): Cron {
		++$this->cron_created;
		$this->test_cron = new TestCron();
		return $this->test_cron;
	}
}

final class TestOptions extends Options {
	public $init_called = false;

	public function __construct() {}

	public function init() {
		$this->init_called = true;
	}

	public function get_permalink(): string {
		return 'tse_story';
	}
}

final class TestShorthand extends Shorthand {
	public function __construct() {}
}

final class TestTokenManager extends TokenManager {
	public $init_called = false;

	public function __construct() {}

	public function init() {
		$this->init_called = true;
	}
}

final class TestPostType extends PostType {
	public $init_called = false;
	public $post_type = 'tse_story';

	public function __construct() {}

	public function init() {
		$this->init_called = true;
	}
}

final class TestTemplates extends Templates {
	public $init_called = false;

	public function __construct() {}

	public function init() {
		$this->init_called = true;
	}
}

final class TestCron extends Cron {
	public $init_called = false;

	public function __construct() {}

	public function init() {
		$this->init_called = true;
	}
}
