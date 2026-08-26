<?php

declare(strict_types=1);

namespace Shorthand\Tests\Admin;

use Shorthand\Admin\GeneralSettingsPage;
use Shorthand\Core\Version;
use Shorthand\Services\Options;
use Shorthand\Tests\WordPressTestCase;

/**
 * "Site" is the Shorthand entity the WordPress site connects to, and the
 * settings page must only name it where it is actually shown.
 */
final class GeneralSettingsPageTest extends WordPressTestCase {

	public function test_an_unconnected_plugin_offers_a_connection_not_a_workspace(): void {
		$this->assertSame( 'Shorthand Connection', $this->build_workspace_section_title() );
	}

	public function test_an_organisation_token_names_only_the_workspace(): void {
		$this->connect_with_token_type( 'Organisation' );

		$this->assertSame( 'Shorthand Workspace', $this->build_workspace_section_title() );
	}

	public function test_a_team_token_names_the_workspace_and_not_the_site(): void {
		$this->connect_with_token_type( 'Team' );

		$this->assertSame( 'Shorthand Workspace', $this->build_workspace_section_title() );
	}

	public function test_the_site_field_is_labelled_for_its_own_input(): void {
		$this->connect_with_token_type( 'Team' );
		$this->build_settings_sections();

		$field = $this->find_field( 'shorthand_v2_token_team' );

		$this->assertSame( 'Site name on Shorthand', $field['title'] );
		$this->assertSame( 'shorthand_v2_token_team', $field['args']['label_for'] );
	}

	public function test_an_organisation_token_renders_no_site_field(): void {
		$this->connect_with_token_type( 'Organisation' );
		$this->build_settings_sections();

		$this->assertNull( $this->find_field( 'shorthand_v2_token_team' ) );
	}

	private function connect_with_token_type( string $token_type ): void {
		\tests_wp_set_option(
			'shorthand_v2_token_info',
			array(
				'team_id'         => 'team-1',
				'organisation_id' => 'org-1',
				'workspace'       => 'Example Workspace',
				'name'            => 'Example Site',
				'logo'            => '',
				'token_type'      => $token_type,
			)
		);
	}

	private function build_workspace_section_title(): string {
		$this->build_settings_sections();

		foreach ( \tests_wp_get_settings_sections() as $section ) {
			if ( 'shorthand_workspace_section' === $section['id'] ) {
				return (string) $section['title'];
			}
		}

		$this->fail( 'The workspace section was never registered.' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_field( string $id ): ?array {
		foreach ( \tests_wp_get_settings_fields() as $field ) {
			if ( $id === $field['id'] ) {
				return $field;
			}
		}

		return null;
	}

	private function build_settings_sections(): void {
		$page = $this->instantiateWithoutConstructor( GeneralSettingsPage::class );

		$this->setPrivateProperty( $page, 'options', new Options( new Version() ) );
		$this->setPrivateProperty( $page, 'version', new Version() );
		$this->setPrivateProperty( $page, 'settings_page_slug', 'shorthand-options' );

		$this->callPrivateMethod( $page, 'build_settings_sections' );
	}
}
