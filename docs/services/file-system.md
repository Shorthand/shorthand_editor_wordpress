---
title: File system service
purpose: How the plugin writes story files into the WordPress uploads directory on any host, disk or object store.
updated: 2026-09-14
---

# File system service

Every file operation in the story publish path goes through
`Shorthand\Services\Files`. Calling code asks for a publish; it never names a
path, opens an archive, or chooses between hosts.

There is one implementation of the uploads directory, used everywhere. It is
the implementation an object store needs, and an ordinary disk tolerates it
without noticing.

`Shorthand\Services\PostAPI` receives a
`Shorthand\Services\Files\BundleStore` as a constructor argument and calls no
file system function directly.

## Classes

Source: `php/src/lib/Services/Files/`.

| Class | Role |
| --- | --- |
| `Shorthand\Services\Files\Uploads` | The interface: `write()`, `read_into()`, `delete()`, `make_dir()` |
| `Shorthand\Services\Files\WpUploads` | The only implementation, over `WP_Filesystem` |
| `Shorthand\Services\Files\FileSystem` | Boots `WP_Filesystem` once, and nothing else |
| `Shorthand\Services\Files\BundleStore` | Opens a bundle for a post, validating the story ID |
| `Shorthand\Services\Files\Bundle` | One story's files: paths, chunks, publish, delete |
| `Shorthand\Services\Files\Staging` | A scratch directory on local disk, for one request |
| `Shorthand\Services\Files\Archive` | A story ZIP: index, documents, unpack |
| `Shorthand\Services\Files\Manifest` | The name, size, and CRC32 of every bundle file |

## The four operations

`Uploads` offers four calls and no way to ask what is present.

| Call | Contract |
| --- | --- |
| `write( $source_path, $dest_path )` | Copies a local file into uploads, overwriting. Returns `true`, `false`, or a `WP_Error` the host named |
| `read_into( $path, $local_path )` | Appends a file held in uploads to a local file |
| `delete( $path )` | Removes one named file |
| `make_dir( $path )` | Creates a directory and its parents, or reports success without acting |

`read_into()` is the only read, and downloaded chunks are the only thing it
reads. Nothing else in the plugin reads a file back out of uploads.

## Decision: one uploads implementation, no host detection

Nothing in this plugin asks what host it is on. There is no scheme test, no
`VIP_GO_APP_ENVIRONMENT`, no plugin sniffing, no allowlist, and no vendor name
outside a documentation comment. `Shorthand\Tests\Services\FilesTest` asserts
this by scanning the source.

The behaviours an object store forces are all safe on a disk:

| Behaviour | On an object store | On a disk |
| --- | --- | --- |
| Delete by manifest, never by listing | Required; a directory cannot be listed | Correct; the manifest names every file the bundle holds |
| Never call `rmdir()` | Required; it does not work | Leaves empty directories, which cost nothing |
| Unpack locally, then copy | Required; `ZipArchive` ignores stream wrappers | One extra local copy, on a local disk |
| Skip files whose size and CRC32 match | Saves an HTTP round trip each | Saves a disk write each |
| Treat a refused write as an author-facing error | Required past 2000 modifications | Never fires; the match cannot succeed |

An implementation per host would be two code paths, one of which nobody runs
locally, differing in the step most likely to corrupt a story. One path that is
correct on the stricter host is worth more than an optimisation for the looser
one.

The earlier design selected a `RemoteFileSystem` by testing
`wp_upload_dir()['basedir']` for a URL scheme. It was removed with the
implementations it selected.

## Decision: boot WP_Filesystem lazily

`FileSystem::boot()` is not called when a service is constructed. Booting
`WP_Filesystem` loads an admin include, raises the memory limit, and can ask
for credentials; services are constructed on every admin request.

The boot happens on the first call that touches `$wp_filesystem`, and
explicitly in `Staging::open()`, where a publish starts its local work and the
memory raise must come before extraction.

`FileSystem` holds one static flag and one method. It is not a service, is
never injected, and carries no host knowledge.

`FileSystem::boot()` returns `null` when no `WP_Filesystem_Base` is available.
The flag is set only once a valid instance is in hand, so a failed boot is
retried on the next call rather than short-circuiting to an unset global.
`WpUploads::write()` turns `null` into a `WP_Error` naming the file it could
not write; `read_into()` and `delete()` return `false`. An unreachable file
system is a publish error the author sees, not a fatal.

## Decision: nothing enumerates or removes a directory

No `scandir()`, `glob()`, `opendir()`, `readdir()`, `list_files()`, `dirlist()`
or `rmdir()` appears in `Shorthand\Services` or `Shorthand\Services\Files`.
`Shorthand\Tests\Services\FilesTest::test_nothing_enumerates_or_removes_a_directory`
asserts it, ignoring comments.

Every file the plugin has to find again is named, not discovered:

| Files | Named by |
| --- | --- |
| Bundle files, on republish and on delete | `story_manifest` post meta |
| Download chunks, on assembly and on cleanup | `story_pulls` post meta, as a count |

An empty directory is therefore never removed. Chunks are named beside the
bundle directory rather than inside a directory of their own, so that a
download leaves nothing behind that would have to be removed.

## The manifest is the only record

`story_manifest` post meta names every file a bundle holds.
`Shorthand\Services\Files\Bundle::prune()` and `Bundle::delete()` work from it
alone, so a file the manifest does not name can never be removed. Three rules
follow.

A failed copy records what it already wrote. `Bundle::copy()` attaches the
entries written before the failure to its `WP_Error` as `partial_manifest`, and
`Bundle::unpack()` merges them into the stored manifest before returning that
error. Without the merge those files are named by nothing and stay in uploads
for good.

An entry stored without `size` or `crc` is reported through
`_doing_it_wrong()`. `Manifest::from_meta()` drops the entry, and the file it
named becomes unreachable the same way. An absent or non-array meta value stays
silent: a first publish after an upgrade legitimately has no manifest.

`Bundle::commit()` writes the manifest, and
`Shorthand\Services\PostAPI::publish_story_bundle()` calls it last, after the
story's documents are stored. A failure while storing the documents therefore
leaves the previous manifest in place, still naming the previous bundle in
full.

## Archive entry names

`Manifest::from_archive()` validates every entry name in the story ZIP before
it becomes a path. It rejects an empty name, a null byte, a backslash, a
leading `/`, a drive prefix such as `C:`, and any `..` segment, and returns a
`WP_Error` that fails the publish.

Entry names are received rather than generated, and are interpolated into the
bundle path the same way story IDs and nonces are. An unsafe name fails the
whole publish instead of being skipped: a skipped entry would leave the bundle
incomplete and the manifest naming a file that was never written.

## Sidecar plugins

WP Stateless support is not in this plugin and will not be. A sidecar plugin
mirrors the bundle wherever it needs to, driven by four actions and one filter.

| Hook | Fires |
| --- | --- |
| `theshed_story_file_written( $path, $name, $post_id )` | Per file written into the bundle |
| `theshed_story_file_deleted( $path, $name, $post_id )` | Per file removed from the bundle |
| `theshed_story_bundle_published( $manifest, $path, $post_id, $story_id )` | Once per publish, after the copy, before the markup is stored |
| `theshed_story_bundle_deleted( $path, $post_id, $story_id )` | Once, after a bundle is removed |
| `theshed_get_story_url( $url )` | Filters the URL the bundle is served from |

Two properties a sidecar can rely on.

A skipped file does not fire `theshed_story_file_written`. The copy is a diff
against the last publish, so a skipped file is already in uploads, unchanged.
A sidecar that mirrors on the file hook alone stays correct across republishes;
one that needs the whole bundle takes it from
`theshed_story_bundle_published`, whose first argument is the complete
manifest.

Chunk writes announce nothing. A `.part` file is a fragment of a ZIP, is read
back within the same publish, and is deleted when the publish ends. Mirroring
one would move bytes twice for no reader.

## Object store constraints

Observed on WordPress VIP, where uploads are an object store behind a PHP
stream wrapper. These shape every decision above.

| Operation | Behaviour |
| --- | --- |
| `scandir()`, `glob()`, `opendir()`, `list_files()` | Return an empty array or `false` |
| `rmdir()` | Does not work as expected. Clearest case in code: `Shorthand\Services\Files\Bundle::start_download()` |
| `mkdir()` | Returns `true` without creating a directory |
| `unlink()` | One HTTP `DELETE` per file |
| `rename()` | Implemented as copy then delete |
| `ZipArchive::extractTo()` | Uses native syscalls; ignores the stream wrapper |
| One path, more than 2000 modifications | Refused |
| File names | Case-insensitive |

Reference: https://docs.wpvip.com/vip-file-system/media-uploads/

## Case and the manifest

Case reaches very little of a path. A bundle lives at
`shorthand/{post_id}/{story_id}`: `{post_id}` is digits, and `{story_id}` is
written once, by `Shorthand\Services\PostAPI::connect_story()`, which creates
the post it links. Two posts therefore cannot fold onto one bundle, however
their story IDs are cased. The download nonce is `wp_rand( 10000, 99999 )`, so
`docs/{nonce}` and the `.part` chunks beside the bundle carry no case either.

What case-insensitivity can still merge is two file names inside one bundle:
one in the stored manifest, one in the manifest of the publish now running.
`Bundle::is_unchanged()` is a keyed lookup and `Manifest::removed()` is an
`array_diff_key()`, both case-sensitive. A file renamed only in case —
`assets/media/Photo.JPG` to `assets/media/photo.jpg` — is written under the new
name, and the old name then reads as departed.

`Manifest::removed()` keeps a stored name that folds to a name in the new
manifest, so the prune never deletes what the copy has just written. On a disk
the two names are two files and the old one survives, named by no manifest.
That orphan costs storage. The delete it replaces costs the story an asset,
silently: a bundle is never read back, and the next publish finds the name in
the manifest with a matching size and CRC32 and skips the write, so the file
does not come back.

`Shorthand\Services\StoryId` declines `sanitize_key()` because it lowercases,
"which would merge two story IDs differing only in case". That is an argument
about the identifier — the value in post meta and in calls to the Shorthand API
— not about uploads, where case is not a distinction the host keeps.

## Reporting a refused write

`WpUploads::write()` turns a refused write into a `WP_Error` carrying a
`pretty` message for the author, which
`Shorthand\Services\PostAPI::set_story_update_error()` stores in
`story_update_error` like any other publish failure.

The refusal does not arrive as a status code. The uploads host's API client has
no branch for it: it returns a generic `upload_file-failed` error with the
status embedded in the message as `(response code: 405)`.
`WP_Filesystem::copy()` leaves that error on its public `errors` property and
answers false. `WpUploads::is_write_cap_refusal()` reads it there, and is the
only place in this codebase that depends on that text. When the match fails,
the plain write failure surfaces unchanged.

`errors` is never cleared, and `FileSystem::boot()` returns one instance per
request. `WpUploads::write()` therefore counts the `upload_file-failed`
messages immediately before `copy()` and matches only the messages that write
added. A plain failure after an earlier refusal reads as a plain failure.

The match runs on every host. On a host that cannot produce this error it
cannot succeed, so it costs one string comparison per failed write and needs no
host test to guard it.

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

## Testing without an object store

`Shorthand\Tests\Support\FakeUploads` implements `Uploads` as an in-memory
object store: keys and bytes, no directories, and counters for writes, deletes
and `make_dir()` calls. `fail_writes( $error, $after )` makes every write after
the first `$after` return a given `WP_Error`, which is how a partial copy is
staged.

`Shorthand\Tests` boots no WordPress. `php/tests/bootstrap.php` stubs what the
file system path needs, including `tests_wp_set_filesystem_available()` for a
boot that finds no `WP_Filesystem_Base`, `tests_wp_set_copy_failure()` for a
plain copy failure that leaves `WP_Filesystem::errors` untouched, and
`tests_wp_doing_it_wrong()` for the calls `Manifest::from_meta()` makes.

The counts are the assertion that matters. A republish with no edits performs
two writes and two deletes — the two documents, which move each publish —
whatever the size of the story. See
`Shorthand\Tests\Services\PostAPIUnpackTest`.
