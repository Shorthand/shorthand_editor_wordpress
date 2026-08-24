---
title: Plugin options
purpose: Every WordPress option the plugin registers, what sets it, and what removes it.
updated: 2026-08-25
---

# Plugin options

`Shorthand\Services\Options::register()` registers every option the
plugin owns. Options fall into two groups: those an administrator edits on the
settings screen, and those the plugin writes for itself.

## Settings screen options

Group `theshed-general-options-group`.

| Option | Type | Default | Sanitizer | Field |
| --- | --- | --- | --- | --- |
| `shorthand_permalink` | string | `story` | `sanitize_text_field` | yes |
| `shorthand_regex_list` | string | `''` | `Options::sanitize_regex_list()` | yes |
| `shorthand_disable_staging` | boolean | `false` | `Options::sanitize_checkbox()` | yes |
| `shorthand_disable_cron` | boolean | `false` | `Options::sanitize_checkbox()` | no |
| `shorthand_css` | string | `''` | `wp_kses_no_null` | yes |

`Shorthand\Admin\GeneralSettingsPage` renders a field for every option marked
`yes`. `shorthand_disable_cron` is registered but has no field: set it with
`wp option update shorthand_disable_cron 1`.

## Internal options

Group `theshed-internal-options-group`. Written by the plugin, not edited by
hand.

| Option | Type | Default | Holds |
| --- | --- | --- | --- |
| `shorthand_v2_token` | string | `''` | Shorthand API token |
| `shorthand_v2_token_info` | array | `null` | Claims decoded from the token |
| `shorthand_auth_state` | array | `null` | Connection state machine |
| `shorthand_v2_signing_key` | string | `null` | Current signing key |
| `shorthand_v2_verifying_key` | string | `null` | Current verifying key |
| `shorthand_v2_next_signing_key` | string | `null` | Rotated-in signing key |
| `shorthand_v2_next_verifying_key` | string | `null` | Rotated-in verifying key |

## shorthand_disable_staging

Staging is the default. This option turns it off, reverting to
`ZipArchive::extractTo()` straight into the bundle directory, for sites where
the extra local copy costs more than it saves.

The choice is withdrawn where uploads are remote.
`Options::can_disable_staging()` reports whether it is available, and
`Options::is_staging_enabled()` then reports `true` whatever the stored option
says. The settings screen renders the checkbox disabled, with a sentence from
`Shorthand\Admin\UploadsHostNotice`.

See `docs/services/file-system.md` for how a remote uploads directory is
detected, and `docs/flows/publishing.md` for what staging does.

## shorthand_disable_cron

Publishing is scheduled on WP-Cron by default. This option turns scheduling off,
so `Shorthand\Admin\Editor::save_shorthand_story()` downloads and unpacks the
story in the request that saves the post. A debug override.

`Options::is_publishing_async()` reports the inverse of the stored option.

The synchronous path uses a different API endpoint, reports failure with
`wp_die()`, and reports no progress. See `docs/flows/publishing.md`.

## Uninstall

`php/src/uninstall.php` deletes every option in this document, plus options no
longer registered but possibly still present: `shorthand_flush_rewrite_rules`,
`shorthand_app_url`, and `shorthand_api_url`. It also deletes the
`theshed_update_info` transient.
