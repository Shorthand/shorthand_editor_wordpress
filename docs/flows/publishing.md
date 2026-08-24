---
title: Publishing a story
purpose: The end-to-end path from a Shorthand story archive to a rendered WordPress post.
updated: 2026-08-25
---

# Publishing a story

Publishing downloads a story archive from Shorthand in chunks, unpacks it, and
copies only the changed files into the uploads directory. It is driven by
WP-Cron.

Entry point: `Shorthand\Services\PostAPI::pull_story_begin()`.

A synchronous path does the same work in the request that saves the post. It is
a debug override, off by default, and described under
`## Synchronous publishing`.

## Directories

Four terms, each naming one thing, used nowhere else in this codebase with a
different meaning.

- **Pull directory** — the downloaded archive chunks for one pull. In uploads,
  at `shorthand/{post_id}/{story_id}_{nonce}/`, holding `file-0.part` …
  `file-{N}.part`. Deleted when the pull ends, whether it succeeds or fails.

- **Staging directory** — the assembled archive and the unpacked tree for one
  pull. On the local disk returned by `get_temp_dir()`, at
  `sh_pull_{nonce}_{random}/`, holding `archive.zip` and `unpacked/`. Lives for
  one request, and is never readable by a later one.

- **Bundle directory** — the published story files. In uploads, at
  `shorthand/{post_id}/{story_id}/`, one per story, overwritten in place on
  republish, kept until the post is deleted. Media sits at the paths the
  archive names; the two documents sit under `docs/{nonce}/`.

- **Manifest** — the name, size, and CRC32 of every file in the bundle
  directory, stored in the `story_manifest` post meta key. Shape:
  `docs/models/story-post-meta.md`.

## Sequence

1. `pull_story_begin()` requests a download URL and creates the pull directory.
2. Successive WP-Cron events download 5 MB ranges into the pull directory.
3. On the final chunk, the chunks are concatenated into the staging directory.
4. The archive is unpacked into the staging directory.
5. The unpacked tree is copied into the bundle directory, skipping files whose
   name, size, and CRC32 match the stored manifest. `article.html` and
   `head.html` are copied to `docs/{nonce}/`.
6. Files present in the manifest but absent from the archive are deleted.
7. `story_head` and `story_body` post meta are written, with asset URLs
   rewritten.
8. `story_manifest` post meta is written.
9. The staging directory and the pull directory are deleted.

Steps 5 and 6 run through `Shorthand\Services\FileSystemService`, never through
a direct `ZipArchive::extractTo()` into uploads. See
`docs/services/file-system.md`.

## Decision: unpack into a local staging directory, then copy

`ZipArchive::extractTo()` uses native syscalls and ignores PHP stream wrappers,
so it cannot write into a remote uploads directory at all. Extracting locally
first also makes the copy step a plain file-to-file copy on every host.

The `shorthand_disable_staging` option reverts to extracting straight into the
bundle directory, for sites where the extra local copy costs more than it
saves. `Shorthand\Services\Options::can_disable_staging()` withdraws the choice
where uploads are remote, and `is_staging_enabled()` then reports `true`
whatever the stored option says.

## Decision: the manifest is a copy diff, not a deletion ledger

Step 5 compares the archive manifest against the stored manifest per file:

| Case | Action |
| --- | --- |
| Name, size, and CRC32 all match | Skip |
| CRC32 differs | Overwrite |
| Absent from the stored manifest | Write |
| Absent from the archive | Delete |

CRC32 with size detects change between two exports of one story. It is not a
security boundary.

An absent manifest means copy every file. That is the state after upgrading
from a plugin version that did not write one, and it needs no migration.

`Shorthand\Services\BundleManifest::from_archive()` builds the archive side
from `ZipArchive::statIndex()`, which returns name, size, and CRC32 without
extracting.

## Decision: write the manifest after the copy, never before

Step 8 follows step 5. A stale manifest causes over-copying, which is safe. A
manifest written early would claim files were copied when they were not, and
the next publish would skip them permanently.

## Decision: version the two documents by pull nonce

The bundle path is a function of `(post_id, story_id)`, both fixed for the life
of the post, so a republish writes the same paths again. A remote uploads host
refuses a path after 2000 modifications. With the copy diff in place, only
these paths accrue any:

| Path | Accrues a modification |
| --- | --- |
| `assets/*`, `static/*` | On real edits only |
| `theme-{hash}.min.css` | Never; the file name carries a hash of its content |
| `docs/{nonce}/article.html`, `docs/{nonce}/head.html` | Never; the nonce is unique per publish |

`{nonce}` is the pull nonce, already stored in `story_update_nonce`. It is
always present and never repeats, unlike the content version, which is nullable
and repeats on a forced re-sync. It is interpolated into a path, so it is
validated with `Shorthand\Services\StoryId::is_valid()`; a nonce that fails
leaves the documents at the root of the bundle, where they sat before they were
versioned.

The previous publish's documents are removed by the copy diff, at a cost of two
deletes.

Moving the documents makes the manifest key differ from the archive name for
those two entries. `docs/models/story-post-meta.md` describes how that is
recorded.

Nothing in this plugin reads the documents back from disk — their content is
stored in `story_head` and `story_body`. They are written because their path is
the third argument of the `theshed_post_process_body` and
`theshed_post_process_head` filters.

A republish of an unedited story performs two writes and two deletes, both of
them documents, whatever the size of the story. See
`Shorthand\Tests\Services\PostAPIUnpackTest`.

## Synchronous publishing

`Shorthand\Services\PostAPI::pull_story_now()` replaces steps 1–4 with a single
`GET /v2/stories/{story_id}`, streamed to a local temporary file, then runs
steps 5–9 unchanged. Reached only when the `shorthand_disable_cron` option is
set. Option: `docs/models/options.md`.

It differs from the WP-Cron path in four ways that matter.

| | WP-Cron path | Synchronous path |
| --- | --- | --- |
| Endpoint | `POST /v2/stories/{id}/generate`, then ranged `GET` | `GET /v2/stories/{id}` |
| Failure | Recorded in `story_update_error`, shown in the editor | `wp_die()` halts the save |
| Progress | `story_update_progress` polled by the editor | None |
| Peak cost | One 5 MB chunk per request | Whole archive plus the unpacked tree in one request |

The last row is the reason it stays off. Staging adds a full local copy of the
story, and a remote uploads directory adds one HTTP request per copied file.
Both land in a single `save_post` request, under `max_execution_time`.

## Pull tracking

A pull directory cannot be listed, so the `story_pulls` post meta key records
what each pull left behind: `{path, files}` per request nonce.

- `pull_story_begin()` sweeps every entry that is not its own nonce, unlinking
  `file-0.part` … `file-{files-1}.part` by name, then records its own.
- `pull_story_chunk()` raises the recorded count after each chunk lands.
- `pull_story_cleanup()` unlinks the chunks and drops the entry, on both a
  successful and a failed pull.

A superseded pull returns early without cleaning up. Its entry survives until
the next `pull_story_begin()` sweeps it.

## Removing a bundle

Deleting a post runs `Shorthand\Services\PostAPI::delete_story_bundle()`, which
tries `delete_tree()` and falls back to `delete_manifest()` where the host
refuses it. The `{post_id}` parent directory is left in place: it cannot be
listed, so it cannot be known to be empty.

## Rejected designs

Neither of these is present in the code.

- **Versioned bundle directories.** A fresh directory per publish makes every
  file new, which defeats the copy diff at step 5. Splitting the layout so that
  documents are versioned and `assets/` is shared would work, because
  `Shorthand\Services\StoryContentTransformer::rewrite_story_bundle_paths()`
  already rewrites the two prefixes separately. Unversioned bundling is the
  existing behaviour, and was kept.

- **Per-file atomic replacement.** Copying each changed file to a temporary
  name and renaming it would remove the truncated-file failure mode, at the
  cost of a second cleanup path for abandoned temporary files.
