---
title: Publishing a story
purpose: The end-to-end path from a Shorthand story archive to a rendered WordPress post.
updated: 2026-09-01
---

# Publishing a story

Publishing downloads a story archive from Shorthand in chunks, unpacks it, and
copies only the changed files into the uploads directory. It is driven by
WP-Cron and is always asynchronous.

Entry point: `Shorthand\Services\PostAPI::pull_story_begin()`.

## Places

Four terms, each naming one thing, used nowhere else in this codebase with a
different meaning.

- **Chunks** — the downloaded archive fragments of one pull. In uploads, beside
  the bundle directory, at `shorthand/{post_id}/{story_id}_{nonce}_{N}.part`.
  Deleted when the pull ends, whether it succeeds or fails. They sit beside the
  bundle rather than in a directory of their own, because an empty directory
  cannot be removed on every host and every pull would leave one behind.

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

1. `pull_story_begin()` requests a download URL and creates the directory the
   bundle sits in. A `429` from Shorthand means the workspace is at its
   concurrent build cap; the publish fails at once, and the author chooses when
   to retry.
2. Successive WP-Cron events download 5 MB ranges into the chunk files.
3. On the final chunk, the chunks are concatenated into the staging directory.
4. The archive is unpacked into the staging directory.
5. The unpacked tree is copied into the bundle directory, skipping files whose
   name, size, and CRC32 match the stored manifest. `article.html` and
   `head.html` are copied to `docs/{nonce}/`.
6. Files present in the manifest but absent from the archive are deleted.
7. `story_manifest` post meta is written.
8. `story_head` and `story_body` post meta are written, with asset URLs
   rewritten.
9. The story's plain text is mirrored into `post_content` and `post_excerpt`,
   so core search and listing views have something to read.
10. The staging directory and the chunks are deleted.

Steps 3 to 7 are one call, `Shorthand\Services\Files\Bundle::publish()`.
`Shorthand\Services\PostAPI` names no path, opens no archive, and makes no
choice between hosts. See `docs/services/file-system.md`.

## Decision: unpack into a local staging directory, then copy

`ZipArchive::extractTo()` uses native syscalls and ignores PHP stream wrappers,
so it cannot write into an object store at all. Extracting locally first also
makes the copy step a plain file-to-file copy on every host.

This is unconditional. There is no option to extract straight into the bundle
directory, and no host test that would decide when to. One publish path is
worth more than an optimisation that only an ordinary disk could take.

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

`Shorthand\Services\Files\Manifest::from_archive()` builds the archive side
from `ZipArchive::statIndex()`, which returns name, size, and CRC32 without
extracting.

## Decision: write the manifest after the copy, never before

Step 7 follows steps 5 and 6, and a failed copy returns before it. A stale
manifest causes over-copying, which is safe. A manifest written early would
claim files were copied when they were not, and the next publish would skip
them permanently.

Step 7 precedes step 8, so a failure while storing markup leaves a manifest
describing files that do exist. That is the safe direction.

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
and repeats on a forced re-sync. It is interpolated into two paths — the
documents directory and the staging directory name — so it is validated with
`Shorthand\Services\StoryId::is_valid()` before either. A nonce that fails
leaves the documents at the root of the bundle, where they sat before they were
versioned, and the publish otherwise proceeds.

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

## Pull tracking

Uploads cannot be listed, so the `story_pulls` post meta key records what each
pull left behind: a chunk count per request nonce.

- `pull_story_begin()` sweeps every entry that is not its own nonce, deleting
  chunk `0` to `{count-1}` by name, then records its own.
- `pull_story_chunk()` raises the recorded count after each chunk lands.
- `pull_story_cleanup()` deletes the chunks and drops the entry, on both a
  successful and a failed pull.

Both call `Shorthand\Services\Files\Bundle::discard_download()`, which
rebuilds the chunk paths from the nonce and the count. An entry written by a
plugin version that stored `{path, files}` is still read for its count.

A superseded pull returns early without cleaning up. Its entry survives until
the next `pull_story_begin()` sweeps it.

## Removing a bundle

Deleting a post runs `Shorthand\Services\PostAPI::delete_story_bundle()`,
which opens the bundle and calls
`Shorthand\Services\Files\Bundle::delete()`. Every file the manifest names is
deleted, then the manifest itself. There is no tree walk and no fallback: the
manifest is the record on every host.

Directories are left in place. They cannot be listed, so they cannot be known
to be empty, and an object store has none to remove.

A file absent from the manifest survives. The manifest is written after every
successful copy, so the only way to reach that state is a plugin version that
did not write one.

## Rejected designs

Neither of these is present in the code.

- **Versioned bundle directories.** A fresh directory per publish makes every
  file new, which defeats the copy diff at step 5. Versioning the documents
  alone gets the benefit without the cost, and is what the code does.

- **A second uploads implementation for ordinary hosts.** It would differ in
  the copy and delete steps, which are the steps most likely to corrupt a
  story, and only one of the two would ever run on a developer's machine. See
  `docs/services/file-system.md`.

- **WP Stateless support in this plugin.** It is a sidecar plugin's job, and
  the hooks it needs are published. See `docs/services/file-system.md`.

- **Per-file atomic replacement.** Copying each changed file to a temporary
  name and renaming it would remove the truncated-file failure mode, at the
  cost of a second cleanup path for abandoned temporary files.
