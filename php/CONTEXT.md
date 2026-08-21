# Story Publish Pipeline

Directory vocabulary, sequence, and file system constraints for publishing a
Shorthand story into WordPress.

## Vocabulary

Four terms. Each names one thing, used nowhere else in this codebase with a
different meaning.

- **Pull directory** — holds the downloaded archive chunks for one pull.
  Location: uploads, at `shorthand/{post_id}/{story_id}_{nonce}/`.
  Contents: `file-0.part` … `file-{N}.part`.
  Lifetime: one pull. Deleted when the pull ends, succeeds or fails.

- **Staging directory** — holds the assembled archive and the unpacked tree
  for one pull. Location: the local temp directory returned by
  `get_temp_dir()`. Lifetime: one request. Never readable by a later request.

- **Bundle directory** — holds the published story files. Location: uploads,
  at `shorthand/{post_id}/{story_id}/`. Lifetime: until the post is deleted.
  One per story, overwritten in place on republish.

- **Manifest** — records the name, size, and CRC32 of every file in the bundle
  directory. Stored in the `story_manifest` post meta key. One per bundle.

## Publish sequence

1. `pull_story_begin()` requests a download URL and creates the pull directory.
2. Successive WP-Cron events download 5 MB ranges into the pull directory.
3. On the final chunk, the chunks are concatenated into the staging directory.
4. The archive is unpacked into the staging directory.
5. The unpacked tree is copied into the bundle directory, skipping files whose
   name, size, and CRC32 match the stored manifest.
6. Files present in the manifest but absent from the archive are deleted.
7. `story_head` and `story_body` post meta are written with rewritten asset
   URLs.
8. `story_manifest` post meta is written.
9. The staging directory and the pull directory are deleted.

Steps 5 and 6 run through the `FileSystem` platform service, not through
direct `ZipArchive::extractTo()` into uploads.

## Manifest invariants

- Written at step 8, after the copy succeeds. Never before.
- A stale manifest causes over-copying, which is safe. A manifest written
  early would claim files were copied when they were not, and the next
  publish would skip them permanently.
- Absent manifest means copy every file. This is the state after upgrading
  from a plugin version that did not write one, and needs no migration.
- Built from `ZipArchive::statIndex()`, which returns name, size, and CRC32
  without extracting.
- CRC32 with size is sufficient. This detects change between two exports of
  one story; it is not a security boundary.

## VIP File System constraints

The uploads directory on WordPress VIP is an object store behind a PHP stream
wrapper. `wp_upload_dir()['basedir']` returns `vip://wp-content/uploads`.

| Operation | Behaviour |
| --- | --- |
| `scandir()`, `glob()`, `opendir()`, `list_files()` | Return an empty array or `false` |
| `rmdir()` | Does not work as expected |
| `mkdir()` | Returns `true` without creating a directory |
| `unlink()` | One HTTP `DELETE` per file, via `Api_Client::delete_file()` |
| `rename()` | Implemented as copy then delete |
| `ZipArchive::extractTo()` | Uses native syscalls; ignores the stream wrapper |
| Overwriting one path more than 2000 times | HTTP `405 Method Not Allowed` |
| File names | Case-insensitive |

Two consequences drive the design above:

- Nothing can be enumerated, so deletion needs the manifest.
- Every write and every delete is an HTTP round-trip, so the copy step skips
  unchanged files rather than rewriting the tree.

Reference: https://docs.wpvip.com/vip-file-system/media-uploads/

### Write ceiling

The bundle path is a function of `(post_id, story_id)`, both fixed for the life
of the post, so republishing writes the same paths again. With the manifest
diff in place, only these accrue modifications:

| Path | Accrues |
| --- | --- |
| `assets/*`, `static/*` | On real edits only; skipped when name, size and CRC32 match |
| `theme-{hash}.min.css` | Never; the file name carries a hash of the file's content |
| `article.html`, `head.html` | Every publish |

The two documents therefore set the ceiling, at 2000 republishes of one story.

## FileSystem service

Every file system call in the publish path goes through
`Shorthand\Services\FileSystemService`. `Shorthand\Services\PostAPI` holds one,
receives it as a constructor argument, and calls no file system function
directly.

| Class | Role |
| --- | --- |
| `FileSystemService` | The interface. Staging directories, directory creation, chunk joining, tree copy, file and manifest deletion |
| `BaseFileSystem` | Everything that does not depend on the uploads host, including the copy diff |
| `LocalFileSystem` | Uploads are a plain path. Trees can be enumerated and removed |
| `RemoteFileSystem` | Uploads are an object store. `delete_dir()` succeeds without acting, `delete_tree()` is refused |
| `FileSystem` | Boots `WP_Filesystem`, and picks the implementation with `create()` |

`FileSystem::create()` chooses by URL scheme, never by vendor identity. See
`docs/adr/0001-detect-remote-uploads-by-scheme.md`.

The staging directory is local on every host, so `BaseFileSystem` enumerates it
freely. Nothing enumerates the bundle directory.

### Testing without VIP

`Shorthand\Tests\Support\FileSystemContractTestCase` states the contract, and
runs against both implementations:

- `Shorthand\Tests\Services\LocalFileSystemTest` uses
  `CountingLocalFileSystem`, a `LocalFileSystem` writing to a real temp tree,
  with counters added.
- `Shorthand\Tests\Services\RemoteFileSystemTest` uses `FakeRemoteFileSystem`,
  a `RemoteFileSystem` whose uploads directory is an in-memory object store.
  `make_dir()` reports success without creating anything, and every write and
  delete is counted.

The counts are the assertion that matters: a republish with no edits performs
zero writes and zero deletes on both.

## Post meta keys

| Key | Holds |
| --- | --- |
| `story_id` | Shorthand story identifier |
| `story_version` | Content version of the published bundle |
| `story_head` | Rendered `<head>` markup, asset URLs rewritten |
| `story_body` | Rendered article markup, asset URLs rewritten |
| `story_manifest` | Name, size, and CRC32 per bundle file |
| `story_update_nonce` | Nonce of the in-flight pull |
| `story_update_state` | Progress of the in-flight pull |
| `story_pulls` | Pull directories awaiting cleanup |

## Out of scope

Two designs were considered and rejected for this pipeline. Neither is
present in the code.

- **Versioned bundle directories.** A fresh directory per publish makes every
  file new, which defeats the manifest diff at step 5. Splitting the layout so
  that documents are versioned and `assets/` is shared would work, because
  `StoryContentTransformer::rewrite_story_bundle_paths()` already rewrites the
  two prefixes separately, and it would lift the write ceiling. It is not done
  here, because unversioned bundling is the existing behaviour.

- **Per-file atomic replacement.** Copying each changed file to a temporary
  name and renaming it would remove the truncated-file failure mode, at the
  cost of a second cleanup path for abandoned temporary files.
