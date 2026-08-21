# ADR 0001: Detect remote uploads by URL scheme

- Status: Proposed
- Date: 2026-08-21

## Decision

Decide whether the uploads directory is remote by testing whether
`wp_upload_dir()['basedir']` carries a URL scheme:

```php
$is_remote = (bool) preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $basedir );
```

A match selects the remote `FileSystem` implementation, forces the staging
directory on, and disables the staging checkbox in the settings page.

Do not test for `VIP_GO_APP_ENVIRONMENT`, for the presence of the WP Stateless
plugin, or for any other host or plugin identity, when deciding behaviour.

## Context

The plugin unpacks a story archive into the uploads directory. On a local file
system this is a direct `ZipArchive::extractTo()`. On an object store behind a
PHP stream wrapper it cannot be, because `extractTo()` uses native syscalls and
ignores stream wrappers, and because nothing in the tree can be enumerated
afterwards.

The property that matters is therefore "is the uploads directory served
through a stream wrapper", not "which vendor hosts this site".

Every host that rewrites the uploads directory does so through the same
WordPress filter, `upload_dir`, and every one of them produces a `basedir`
carrying a scheme:

| Host | `wp_upload_dir()['basedir']` |
| --- | --- |
| WordPress VIP | `vip://wp-content/uploads` |
| WP Stateless, `stateless` mode | `gs://{bucket}/{root_dir}` |
| WP Offload Media, server offload | `s3://{bucket}/{path}` |
| Ordinary hosting | `/var/www/html/wp-content/uploads` |

A vendor allowlist gets this wrong in both directions.

It is too broad. WP Stateless has five modes and applies the `upload_dir`
filter in one of them. Detecting the plugin would force staging on for sites in
`cdn`, `backup`, `ephemeral`, and `disabled` modes, whose uploads are local and
need none of it.

It is too narrow. It misses WP Offload Media, and it misses every host that
does not exist yet. Each new one is a code change in the publish path.

## Consequences

The publish path contains no vendor identifiers. `PostAPI` asks the
`FileSystem` factory for a service and never learns which host it is on.

Vendor identity is still needed, in one place: the settings page must explain
why the staging checkbox is disabled, and a generic explanation is worse than a
specific one. Vendor detection therefore lives in the settings layer and
produces a message, never a behaviour. It carries named cases for WordPress
VIP, for WP Stateless in `stateless` mode, and for WP Offload Media, plus a
fallback naming the scheme that was found, for hosts with no named case.

Splitting it this way keeps the list that must be maintained in the low-risk
place. A missing vendor message degrades to a generic sentence. A missing
vendor in a behaviour allowlist corrupts a story bundle.

The test is a string match on a value WordPress computes on every request, so
it costs nothing and needs no caching.

A host that serves uploads through a stream wrapper registered under a scheme
that WordPress resolves to a local path would be misclassified. No such host is
known.
