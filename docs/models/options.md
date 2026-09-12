---
title: Plugin options
purpose: Every WordPress option the plugin registers, what sets it, and what removes it.
updated: 2026-09-01
---

# Plugin options

`Shorthand\Services\Options::register()` registers every option the
plugin owns. Options fall into two groups: those an administrator edits on the
settings screen, and those the plugin writes for itself.

## Settings screen options

Group `theshed-general-options-group`.

| Option | Type | Default | Sanitizer |
| --- | --- | --- | --- |
| `shorthand_permalink` | string | `story` | `sanitize_text_field` |
| `shorthand_regex_list` | string | `''` | `Options::sanitize_regex_list()` |
| `shorthand_css` | string | `''` | `wp_kses_no_null` |

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

## Removed options

`Options::remove_legacy_options()` runs on upgrade and deletes options the
plugin no longer reads.

| Option | Removed because |
| --- | --- |
| `shorthand_disable_cron` | Publishing is always asynchronous; the synchronous debug override is gone |
| `shorthand_disable_staging` | Unpacking is always local; there is no longer a path that skips it. See `docs/services/file-system.md` |

Test the option's presence with a sentinel default, not with `false !==`. A
checkbox left unticked stores a falsy value, and a `false !==` test would leave
that row behind.

## Uninstall

`php/src/uninstall.php` deletes every option in this document, plus options no
longer registered but possibly still present: `shorthand_flush_rewrite_rules`,
`shorthand_app_url`, and `shorthand_api_url`. It also deletes the
`theshed_update_info` transient.
