---
title: Story post meta
purpose: The post meta keys a Shorthand story post carries, and the shape of the structured ones.
updated: 2026-08-25
---

# Story post meta

A Shorthand story post holds its identity, its rendered markup, and the state
of any in-flight pull in post meta. `post_content` and `post_excerpt` hold a
plain text copy of the story, for core search and listing views only; the
rendered page is built from `story_body`.

## Keys

| Key | Type | Holds |
| --- | --- | --- |
| `story_id` | string | Shorthand story identifier |
| `story_version` | number | Content version of the published bundle |
| `story_head` | string | Rendered `<head>` markup, asset URLs rewritten |
| `story_body` | string | Rendered article markup, asset URLs rewritten |
| `story_manifest` | object | Name, size, and CRC32 per bundle file |
| `story_update_nonce` | string | Nonce of the in-flight pull |
| `story_update_state` | object | Progress of the in-flight pull |
| `story_pulls` | object | Pull directories awaiting cleanup |
| `story_excerpt` | string | The excerpt last generated from the story body |
| `story_update_error` | array | Last publish failure, as a flattened `WP_Error` |

`Shorthand\Plugin\PostType::register_post_type()` registers every key except
`story_update_error`, which is written directly by
`Shorthand\Services\PostAPI::set_story_update_error()`.

Only `story_id` and `story_version` are exposed over REST.

## story_id

Validated against `/^[A-Za-z0-9]+$/` by
`Shorthand\Services\StoryId::is_valid()`, and reduced to an empty string by
`Shorthand\Services\StoryId::sanitize()`, which is the registered
`sanitize_callback`.

Do not use `sanitize_key()`. It lowercases, which would merge two story IDs
differing only in case. Do not use `sanitize_text_field()`, which leaves `/`,
`\` and `..` in place. The value is interpolated into a file system path, so it
is validated against an allowlist rather than transformed.

## story_manifest

One entry per file in the bundle directory, keyed by bundle path:

```php
array(
    'assets/media/image.jpg' => array( 'size' => 84213, 'crc' => 2145678901 ),
)
```

Built by `Shorthand\Services\BundleManifest`: `from_archive()` from
`ZipArchive::statIndex()`, `from_meta()` from the stored value.

Keys are bundle paths, not archive paths. The two differ for `article.html` and
`head.html`, which the archive names at its root and the bundle holds under
`docs/{nonce}/`. During a publish those entries carry an extra `from` key
naming the archive path; `Shorthand\Services\BaseFileSystem::copy_tree()` reads it,
then strips it before storage, so the stored manifest describes the bundle only.

An absent `story_manifest` means copy every file. That is the state after
upgrading from a plugin version that did not write one, and it needs no
migration.

The key is written only after a successful copy. See `docs/flows/publishing.md`.

## story_pulls

One entry per in-flight request nonce, recording what that pull left in the
uploads directory:

```php
array(
    '9f2c…' => array( 'path' => 'shorthand/12/abc123_9f2c…/', 'files' => 3 ),
)
```

A pull directory cannot be listed on a remote uploads host, so this is the only
record of which chunk files exist. `files` is the count of `file-N.part`
entries written so far.

## story_excerpt

The excerpt `Shorthand\Services\PostAPI::store_story_text()` last wrote to
`post_excerpt`, held as the column holds it, after `excerpt_save_pre` has
re-encoded entities.

The next publish overwrites `post_excerpt` only when it still matches this
value. Any other value, including an empty one, is an author's edit and is
left alone.

## story_update_state

Progress of the in-flight pull, as produced by
`Shorthand\Services\StorySyncProgress::to_array()` and read back by
`from_meta_value()`. Removed when the pull finishes.
