---
title: Story post meta
purpose: The post meta keys a Shorthand story post carries, and the shape of the structured ones.
updated: 2026-10-02
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
| `story_pulls` | object | Download chunks awaiting cleanup |
| `story_excerpt` | string | The excerpt last generated from the story body |
| `story_cover` | object | The Shorthand cover image the last publish evaluated |
| `story_cover_attachment` | number | Attachment the plugin set as the featured image |
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

Built by `Shorthand\Services\Files\Manifest`: `from_archive()` from
`ZipArchive::statIndex()`, `from_meta()` from the stored value. `from_meta()`
drops and reports a key that is not a safe bundle path, under the rules in
`docs/services/file-system.md`, section "Archive entry names".

Keys are bundle paths, which are the archive's own entry names.

An absent `story_manifest` means copy every file. That is the state after
upgrading from a plugin version that did not write one, and it needs no
migration.

The key is written only after a successful copy, and is the only record of
what the bundle holds. It says what to skip on republish, what to delete when
the story changes, and what to remove when the post is deleted. Nothing lists
the bundle directory. See `docs/services/file-system.md`.

## story_pulls

One entry per in-flight request nonce, holding the number of download chunks
that have arrived so far:

```php
array(
    '9f2c…' => 3,
)
```

Uploads cannot be listed, so this is the only record of which chunk files
exist. The paths follow from the post ID, the story ID, the nonce and the
count, and are rebuilt by
`Shorthand\Services\Files\Download::chunk_path()`.

Entries written by a plugin version that stored `array( 'path' => …, 'files' =>
… )` are kept in that shape. Those chunks were written at the older naming, a
directory beside the bundle, so the shape is what tells the sweep which paths
to remove: `Shorthand\Services\Files\Download::discard_legacy()` rather than
`Download::discard()`. `Shorthand\Services\PostAPI` reads both shapes and
writes the count for a new pull.

## story_excerpt

The excerpt `Shorthand\Services\PostAPI::store_story_text()` last wrote to
`post_excerpt`, held as the column holds it, after `excerpt_save_pre` has
re-encoded entities.

The next publish overwrites `post_excerpt` only when it still matches this
value. Any other value, including an empty one, is an author's edit and is
left alone.

## story_cover

The cover image the last publish evaluated, as `GET /v2/stories/:id/settings`
reported it under `meta.cover`, reduced to six keys by
`Shorthand\Services\StoryCover::sanitize()`, which is the registered
`sanitize_callback`:

```php
array(
    'id'     => 'c1',
    'mime'   => 'image/jpeg',
    'name'   => 'cover.jpg',
    'size'   => 1200,
    'width'  => 800,
    'height' => 600,
)
```

The file's address is not stored. The API reports it as `signedUrl`, signed
for a short window, so it is only good for the request that fetched it.
`Shorthand\Services\StoryCover::read()` carries it as `url` on the in-memory
cover, the `shorthand_story_cover_{story_id}` transient, and the editor
refresh payload; the download and the panel image use it from there. A cover
the API reports without `signedUrl` counts as no cover.

`Shorthand\Services\StoryCover::sync()` writes the key on every publish that
finds a cover, whether or not it imports it. It is absent until the first
publish after the feature shipped, and absent for a story with no cover.

The editor refresh, `wp_ajax_shorthand_get_story_cover`, never writes this
key. The editor's "Use story cover now" button, `wp_ajax_shorthand_import_story_cover`,
runs `Shorthand\Services\StoryCover::sync()` with `$replace = true` and writes it
like a publish does. It caches the API response in the `shorthand_story_cover_{story_id}`
transient for five minutes instead. If the refresh wrote `story_cover`, a
cover changed between two publishes would match the stored id at the next
publish and be skipped as already imported.

The `id` inside it is what `Shorthand\Services\StoryCover::state()` compares.
Media files in Shorthand are immutable, so an unchanged id is an unchanged file.

## story_cover_attachment

The attachment ID `Shorthand\Services\StoryCover::sync()` last set as the
featured image. Absent until the plugin has imported a cover.

The featured image rule: the plugin writes the featured image only when it set
the current one, or when there is none. `story_cover_attachment` is how the
plugin knows which one it set. On every publish it is compared with
`get_post_thumbnail_id()`:

| `story_cover_attachment` | `_thumbnail_id` | Outcome |
| --- | --- | --- |
| absent | absent | Import the cover |
| absent | set | Keep the author's image; write `story_cover` only |
| set | equal, cover id unchanged | Nothing to do |
| set | equal, cover id changed | Import the new cover, delete the old attachment |
| set | different or absent | The author changed it; stand down |

The last row holds for every publish. The author hands control back with the
"Use story cover now" button in the editor, which imports with `$replace = true`
and records the new attachment here. The earlier attachment is deleted only
when it was still the featured image; one the author moved away from stays in
the media library.

`Shorthand\Services\StoryCover::state( int $post_id, ?array $cover, ?int $thumbnail = null )`
compares against `$thumbnail` when given. The classic editor holds an unsaved
featured image choice in the form's `_thumbnail_id` field until the post is
saved, so the editor passes that value rather than the saved one.

Both keys are protected by
`Shorthand\Plugin\PostType::is_protected_meta()`, so the Custom Fields box
does not offer them.

## story_update_state

Progress of the in-flight pull, as produced by
`Shorthand\Services\StorySyncProgress::to_array()` and read back by
`from_meta_value()`. Removed when the pull finishes.
