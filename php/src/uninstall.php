<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Uninstall handler for The Shorthand Editor plugin.
 *
 * This file is called when the plugin is deleted from WordPress.
 */

delete_option( 'shorthand_permalink' );
delete_option( 'shorthand_flush_rewrite_rules' );
delete_option( 'shorthand_regex_list' );
delete_option( 'shorthand_css' );
delete_option( 'shorthand_disable_cron' );
delete_option( 'shorthand_disable_staging' );

delete_option( 'shorthand_app_url' );
delete_option( 'shorthand_api_url' );
delete_option( 'shorthand_v2_token_info' );
delete_option( 'shorthand_v2_token' );

delete_option( 'shorthand_v2_signing_key' );
delete_option( 'shorthand_v2_verifying_key' );

delete_option( 'shorthand_v2_next_signing_key' );
delete_option( 'shorthand_v2_next_verifying_key' );

delete_option( 'shorthand_auth_state' );

/* Per-user dismissal timestamp for the auth-state admin notice. */
delete_metadata( 'user', 0, 'shorthand_auth_notice_dismissed_at', '', true );

/* Cached plugin update-check payload. */
delete_transient( 'theshed_update_info' );

/* Any in-flight story pulls scheduled via WP-Cron. */
wp_clear_scheduled_hook( 'shorthand_pull_story_cron' );
