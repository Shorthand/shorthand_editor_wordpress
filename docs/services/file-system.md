---
title: File system service
purpose: How the plugin writes into the WordPress uploads directory on any host, disk or object store.
updated: 2026-08-25
---

# File system service

Every file system call in the story publish path goes through one service, so
that an uploads directory backed by an object store behaves like one backed by
a disk.

`Shorthand\Services\PostAPI` receives a `FileSystemService` as a constructor
argument and calls no file system function directly.

## Classes

| Class | Role |
| --- | --- |
| `Shorthand\Services\FileSystemService` | The interface: staging directories, directory creation, chunk joining, tree copy, file and manifest deletion |
| `Shorthand\Services\BaseFileSystem` | Everything independent of the uploads host, including the copy diff |
| `Shorthand\Services\LocalFileSystem` | Uploads are a plain path; trees can be enumerated and removed |
| `Shorthand\Services\RemoteFileSystem` | Uploads are an object store; `delete_dir()` succeeds without acting, `delete_tree()` is refused |
| `Shorthand\Services\FileSystem` | Boots `WP_Filesystem`, reports the uploads scheme, and picks an implementation in `create()` |

Source: `php/src/lib/Services/`.

## Decision: detect a remote uploads directory by URL scheme

`Shorthand\Services\FileSystem::get_uploads_scheme()` tests
`wp_upload_dir()['basedir']` for a URL scheme, and is the only place the
pattern appears:

```php
preg_match( '#^([a-z][a-z0-9+.\-]*)://#i', $basedir )
```

A match selects `Shorthand\Services\RemoteFileSystem`, forces the staging
directory on, and disables the staging checkbox on the settings screen.

Do not decide behaviour from `VIP_GO_APP_ENVIRONMENT`, from the presence of a
named plugin, or from any other host or vendor identity.

The property that matters is whether uploads are served through a PHP stream
wrapper, not which vendor hosts the site. Every host that redirects uploads
does so through the `upload_dir` filter, and every one produces a scheme:

| Host | `wp_upload_dir()['basedir']` |
| --- | --- |
| WordPress VIP | `vip://wp-content/uploads` |
| WP Stateless, `stateless` mode | `gs://{bucket}/{root_dir}` |
| WP Offload Media, server offload | `s3://{bucket}/{path}` |
| Ordinary hosting | `/var/www/html/wp-content/uploads` |

A vendor allowlist is wrong in both directions. It is too broad: WP Stateless
applies the filter in one of its five modes, so detecting the plugin would
force staging on for sites whose uploads are local. It is too narrow: it misses
WP Offload Media, and every host that does not exist yet.

A host serving uploads through a scheme that WordPress resolves back to a local
path would be misclassified. No such host is known.

## Decision: vendor identity produces messages, never behaviour

The settings screen must say why the staging checkbox is disabled, and a
specific sentence is better than a generic one. `Shorthand\Admin\UploadsHostNotice`
is the only place in this codebase that names a vendor. It carries named cases
for WordPress VIP, WP Stateless in `stateless` mode, and WP Offload Media, and
falls back to naming the scheme it found.

A missing vendor message degrades to a generic sentence. A missing vendor in a
behaviour allowlist corrupts a story bundle.

## Decision: boot WP_Filesystem lazily

`Shorthand\Services\FileSystem::init()` does not run when a service is
constructed. Booting `WP_Filesystem` loads an admin include, raises the memory
limit, and can ask for credentials, and a service is constructed on every admin
request.

The boot happens on the first call that touches `$wp_filesystem`, and
explicitly at `make_temp_dir()`, where a publish starts its local work and the
memory raise must come before extraction.

## Decision: nothing enumerates a directory

Neither implementation lists a directory. `copy_tree()` takes the manifest of
the incoming archive and the manifest of the last publish, copies from the
first, and skips every entry the second already matches.

`Shorthand\Services\BundleManifest` builds both: `from_archive()` reads the
archive index, `from_meta()` reads the `story_manifest` post meta key.

## Remote uploads constraints

Observed on WordPress VIP, where the uploads directory is an object store
behind a PHP stream wrapper.

| Operation | Behaviour |
| --- | --- |
| `scandir()`, `glob()`, `opendir()`, `list_files()` | Return an empty array or `false` |
| `rmdir()` | Does not work as expected |
| `mkdir()` | Returns `true` without creating a directory |
| `unlink()` | One HTTP `DELETE` per file |
| `rename()` | Implemented as copy then delete |
| `ZipArchive::extractTo()` | Uses native syscalls; ignores the stream wrapper |
| One path, more than 2000 modifications | Refused |
| File names | Case-insensitive |

Two consequences shape the publish path. Nothing can be enumerated, so deletion
needs a manifest. Every write and every delete is an HTTP round-trip, so the
copy step skips unchanged files rather than rewriting the tree.

Reference: https://docs.wpvip.com/vip-file-system/media-uploads/

## Reporting a refused write

`Shorthand\Services\RemoteFileSystem::write_file()` turns a refused write into
a `WP_Error` carrying a `pretty` message for the author, which
`Shorthand\Services\PostAPI::set_story_update_error()` stores in
`story_update_error` like any other publish failure.

The refusal does not arrive as a status code. The uploads host's API client has
no branch for it: it returns a generic `upload_file-failed` error with the
status embedded in the message as `(response code: 405)`.
`WP_Filesystem::copy()` leaves that error on its public `errors` property and
answers false. `Shorthand\Services\RemoteFileSystem::is_write_cap_refusal()`
reads it there, and is the only place in this codebase that depends on that
text. When the match fails, the plain write failure surfaces unchanged.

The 2000-modification limit is documented; the status code is not. It appears
in no WordPress VIP document and in no line of `vip-go-mu-plugins`. Treat `405`
as observed rather than promised, which is why a failed match must stay
harmless.

Do not read the refusal from `error_get_last()`. A PHP warning carrying this
message is raised only by the `vip://` stream wrapper, on direct writes with
`file_put_contents()` and `fwrite()`. Every write in this plugin goes through
`WP_Filesystem`, which reaches the host's API client without the wrapper.

Sources:

- https://docs.wpvip.com/vip-file-system/media-uploads/ — the 2000-modification limit.
- https://github.com/Automattic/vip-go-mu-plugins/blob/35ff0ddaa1d996d1adcff99e0fff35d59d051db7/files/class-api-client.php#L144 — `Api_Client::upload_file()` building the message.
- https://github.com/Automattic/vip-go-mu-plugins/blob/968d6196fe98dfd570e09a6271f34b2bb84d085e/files/class-wp-filesystem-vip.php#L243-L247 — `WP_Filesystem_VIP::copy()` leaving it on `errors`.
- https://github.com/Automattic/vip-go-mu-plugins/blob/9e4e16ee1b03519883166d9d5febdaa3b32bc895/files/init-filesystem.php#L26-L45 — the host installing `WP_Filesystem_VIP` as `$wp_filesystem`.
- https://github.com/Automattic/vip-go-mu-plugins/blob/0f890e4d326833a6e23514442f4113d7fc6d41e0/files/class-vip-filesystem-local-stream-wrapper.php#L412-L417 — the stream wrapper path, which this plugin does not use.

## Testing without a remote host

`Shorthand\Tests\Support\FileSystemContractTestCase` states the contract, and
runs against both implementations:

- `Shorthand\Tests\Services\LocalFileSystemTest` uses `CountingLocalFileSystem`,
  a `LocalFileSystem` writing to a real temp tree, with counters added.
- `Shorthand\Tests\Services\RemoteFileSystemTest` uses `FakeRemoteFileSystem`,
  a `RemoteFileSystem` whose uploads directory is an in-memory object store.
  `make_dir()` reports success without creating anything, and every write and
  delete is counted.

The counts are the assertion that matters: a republish with no edits performs
zero writes and zero deletes on both.
