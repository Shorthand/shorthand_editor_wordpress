# WordPress plugin

The WordPress plugin is called _The Shorthand Editor_, and uses the
short name `the-shorthand-editor` internally and for translation.

See the accompanying [LICENSE](./LICENSE) file to understand how development and distribution is permitted.

## Releases

A production release is built and deployed when a version tag is
pushed to the repository. The CircleCI `production-release` workflow
is gated on tags and handles bundling, uploading to the production
S3 bucket, creating a GitHub release, and invalidating CloudFront.

Pushes to `master` trigger a non-production bundle and upload via
the `wp-plugin-workflow`; no `production` branch is used.

The following release checklist should be observed before tagging:

1. The version should be updated in all relevant places
   (See [Version](#plugin-version)).
2. The changelog should be updated in `./deploy/update.json`.
3. Tag `master` with the new version number and push the tag. The
   `tag:release` script in `package.json` reads the version from
   `deploy/update.json` and pushes the tag for you.

## Plugin version

The version of the plugin is currently stored in several files:

1. The top-level plugin file, `php/src/the-shorthand-editor.php`
2. The `Version.php` constant, `php/src/lib/Core/Version.php`
3. The update-check file, `deploy/update.json`
4. The WordPress directory file, `php/src/readme.txt`

When performing a release, the version of the plugin should be
updated in all these places.

## Development

The plugin is written in PHP and TypeScript. No additional PHP tooling is
needed to serve the plugin locally (see below). Its TypeScript components
are built with ESBuild, during `pnpm build`.

It can be bundled as a ZIP for distribution or remote testing.

### PHP and Composer

The source for the plugin does not need any additional PHP tooling to bundle
the plugin for distribution (see [Distribution](#distribution)).

Other tasks such as dependency management or compatibility checking require
the `composer` tool, which may be installed via homebrew.

```bash
brew install composer
```

Dependency libraries are pinned in the `composer.lock` file, but may be
updated by running `composer update` from the `php` directory (see [Third-
party dependencies](#third-party-dependencies) for more information).

Note: the use of `magento/php-compatibility-fork` works around a bug in `phpcompatibility/php-compatibility`
version 9.3.5 which, while fixed in version 10, would cause a conflict with other
dependencies over `squizzlabs/php_codesniffer`.

#### Minimum PHP version

The plugin supports a minimum PHP version of 7.4.

Although this is higher than the initial target of 7.2, this may still
present some hurdles when developing in the presence of later language features.
Therefore, consider the following utilities defined in `composer.json`
which may be run from within the `php` directory with `composer` installed.

After making PHP source modifications, consider downcompiling the source
to the earlier PHP language version. Note that this modifies source in place,
so treat this command as if it were destructive and manage the risk, e.g. by
adding your `php/src` directory to your Git index.

```bash
composer run-script downcompile
```

Before committing your PHP changes, check the source against the
compatibility rules for the lower language version.

```bash
composer run-script check-7.2
```

The PHP test suites are covered in [PHP tests](#php-tests).

#### Third-party dependencies

Best practices for WordPress plugins recommend bundling third-party library
dependencies in a custom namespace to avoid version conflicts between plugins.

The plugin depends on the `firebase/php-jwt` library for signin JWTs. To
incorporate an updated version of the library (and any others), run the
following command from the `php` directory.

```bash
composer run-script prefix-dependencies
```

This will additionally downcompile the source to the lower version of the PHP
language, namespace it under the `Shorthand\Vendor` namespace, and copy it into
the `src/vendor_prefixed` directory. These files are checked in, as they are
pinned, and are distributed as a part of the plugin under their declared licenses.

### Local development

The local WordPress site runs in [wp-env](https://github.com/WordPress/gutenberg/tree/trunk/packages/env),
which needs Docker. `.wp-env.json` describes the site: the latest WordPress
release on PHP 8.3, with the plugin mounted from `php/src` and `public`.

```bash
pnpm install
pnpm build
pnpm env
```

The site is served at `http://localhost:8888`. Sign in as `admin` with the
password `password`. The first start activates the plugin and sets pretty
permalinks (`bin/wp-env-seed.sh`).

```bash
pnpm env:xdebug                       # start with Xdebug listening on port 9003
pnpm env:stop                         # stop the containers, keep the database
pnpm env:cleanup                      # remove the containers and the database
pnpm wp-env run cli wp plugin list    # run WP-CLI inside the site
pnpm wp-env run cli bash              # open a shell inside the site
```

`.vscode/launch.json` has the matching Xdebug configuration, `wp-env (Xdebug)`.

To generate the `meta.json` file, run

```bash
METAFILE=1 pnpm build
```

#### Shorthand stack

The site points the plugin at a local Shorthand dev server (`dylan`) on
`https://localhost:9443`, through these constants in `.wp-env.json`:

| Constant                               | Effect                                                                                    |
| -------------------------------------- | ----------------------------------------------------------------------------------------- |
| `THESHED_API_URL`, `THESHED_APP_URL`   | Where the plugin finds the Shorthand API and app.                                         |
| `THESHED_NO_SSL_VERIFY`                | The plugin accepts the self-signed certificate of a local API.                            |
| `THESHED_FIX_CRON_URL`                 | Cron requests to `https://localhost` go to `host.docker.internal` instead, so they reach the host. |
| `THESHED_BLOCK_UPGRADE`                | WordPress cannot replace the mounted plugin with a released version.                      |

The API URL uses `host.docker.internal` because PHP calls it from inside
Docker. The app URL is opened by the browser, so it uses `localhost`. A remote
dev stack is reached the same way from both, so both URLs take its hostname.

#### Per-developer settings

Values that should not be committed go in `.wp-env.override.json`, which git
ignores. `.wp-env.override.json.example` shows the shape. The `config` and
`mappings` keys merge into `.wp-env.json`; any other key replaces it. Typical
uses:

- `THESHED_API_URL` and `THESHED_APP_URL`, to use a remote Shorthand dev stack
  instead of `dylan`.
- `port`, so that several checkouts run at the same time. Each config file
  gets its own wp-env instance, so checkouts do not share a database.
  `WP_ENV_PORT=8890 pnpm env` does the same for one run.
- Extra plugins mounted side by side, keyed by plugin slug under `mappings`.
  Do not use the `plugins` key: it names a plugin after its source directory,
  and would install `php/src` as `src`.

#### WordPress and PHP versions

Two environment variables override the versions in `.wp-env.json`. Pass
`--update` so that wp-env downloads the new core. Run the cleanup first when
moving to an older core, because WordPress does not downgrade its database
schema.

```bash
pnpm env:cleanup
WP_ENV_CORE=https://wordpress.org/wordpress-6.0.11.zip WP_ENV_PHP_VERSION=7.4 pnpm env --update
```

#### HTTPS

The Shorthand editor only talks to a site over HTTPS, and wp-env serves plain
HTTP. Either of these puts a TLS proxy in front of it:

1. Caddy, with a certificate from `mkcert`. It listens on 8443, because 443 is
   often taken by other local proxies; `PROXY_PORT` changes it.

   ```bash
   mkcert -install
   mkcert -cert-file docker/proxy/certs/local.pem -key-file docker/proxy/certs/local-key.pem localhost wordpress.local "*.localhost"
   WP_PORT=8888 docker compose -f docker/proxy/compose.yml up -d
   ```

   Then browse `https://localhost:8443`. This origin is same-site with a
   `dylan` on `https://localhost:9443`. To test the cross-site case, browse
   `https://wordpress.local:8443` (with `127.0.0.1 wordpress.local` in
   `/etc/hosts`) or any `*.localhost` name, which needs no hosts entry.
2. The `PROXY_LOCAL` route of `dylan`, which forwards a path on its own
   origin: `PROXY_LOCAL=/wordpress:8888:/ pnpm local`, then browse
   `https://localhost:9443/wordpress`.

The must-use plugin `docker/mu-plugins/local-proxy.php` makes WordPress serve
the proxied origin: it marks forwarded requests as HTTPS and takes the site
and home URLs from the forwarded host and prefix. Leave `WP_HOME` and
`WP_SITEURL` unset. wp-env rewrites the port of `WP_SITEURL` to its own, so
setting them sends `wp-admin` back to plain HTTP.

#### PHP tests

There are two suites. Both run in CI on every branch: the unit suite in the
`wp-plugin-php-unit` job, the integration suite in `wp-plugin-php-integration`.

Unit tests live in `php/tests` and run on the host, with no Docker, against
the WordPress stubs in `php/tests/bootstrap.php`:

```bash
pnpm test:php          # or: composer test, from php/
```

Integration tests live in `php/tests/integration` and run inside a second
wp-env instance, `.wp-env.test.json` on port 8889, on top of the WordPress core
test library. Tests extend `WP_UnitTestCase` and get a real database. The
instance is separate because the core library resets that database on every
run. PHPUnit is installed on the host and run in the container:

```bash
composer install -d php/tests/integration
pnpm env:test
pnpm test:php:integration
```

`pnpm test:php:matrix` runs the integration suite against each WordPress/PHP
pair listed in `bin/wp-matrix.sh`, starting and removing the test instance for
each. `MATRIX="6.0.11:7.4" pnpm test:php:matrix` picks other pairs.

##### Integration suite in CI

`wp-plugin-php-integration` in `.circleci/prod-config.yml` runs the same three
commands on a `machine` executor. Every machine job is a new VM, so the job
restores three caches before `pnpm env:test`:

| Cache | Path | Key inputs |
| --- | --- | --- |
| wp-env instance | `~/.wp-env` | `.wp-env.test.json`, `@wordpress/env` version, latest WordPress release |
| Docker images | `~/docker-cache/wp-env-images.tar` | same as the instance |
| Composer vendor | `php/tests/integration/vendor` | `php/tests/integration/composer.lock` |

The instance cache holds WordPress core, the core test library,
`wp-tests-config.php` and wp-env's config checksum. When the checksum matches,
`wp-env start` skips image pulls, image builds, downloads and the WordPress
install. The image tarball holds the two images wp-env builds and
`mariadb:lts`, loaded with `docker load` so Compose does not rebuild them. The
database volume is new on every run; the core test library creates its own
tables. The latest-release key comes from `api.wordpress.org`, written to
`.cache-keys/wp-latest` (ignored by git), so a WordPress release invalidates
both caches instead of running against stale core.

The job is not required by `wp-plugin-bundle-and-upload`. A failure shows on
the pipeline but does not block the master upload.

#### Troubleshooting

- **The plugin's admin pages have no scripts or styles.** `public/scripts` is
  missing. Run `pnpm build`. The seed script prints this warning on start.
- **`wp-admin` redirects from HTTPS to HTTP.** `WP_HOME` or `WP_SITEURL` is set
  in an override file. Remove it and let `local-proxy.php` derive the URLs.
- **The browser rejects the certificate on 8443.** Run `mkcert -install`, then
  generate the certificate again.
- **The plugin cannot connect to a remote dev stack.** `THESHED_API_URL` still
  points at `host.docker.internal`. Set both URLs in the override file.
- **Database errors after changing `WP_ENV_CORE`.** The database was created by
  a newer WordPress. Run `pnpm env:cleanup` and start again.
- **`WP_TESTS_DIR is not set`.** The integration suite was run on the host.
  Use `pnpm test:php:integration`.
- **`Missing vendor/`.** Run `composer install -d php/tests/integration`.
- **Port 8888 is in use.** Another checkout is running. Set `port` in the
  override file, or `WP_ENV_PORT`.
- **Xdebug does not stop at breakpoints.** Start with `pnpm env:xdebug`; a
  plain `pnpm env` starts without it.

### Distribution

The plugin can be bundled as a ZIP file. This is the most convenient
way to share the file for testing or distribution.

To generate a ZIP file of the WordPress plugin for distribution, run

```bash
NODE_ENV=production pnpm bundle
```

When bundling, the `meta.json` file will be generated in
the `dist` folder when `METAFILE=1` is specified.
