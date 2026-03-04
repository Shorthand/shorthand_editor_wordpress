# WordPress plugin

The WordPress plugin is called _The Shorthand Editor_, and uses the
short name `the-shorthand-editor` internally and for translation.

See the accompanying [LICENSE](./LICENSE) file to understand how development and distribution is permitted.

## Releases

A release is built and deployed when the `production` branch
is advanced. The head of the `master` branch should be properly
tagged with the new version number before `production` is
fast-forwarded to this commit.

The following release checklist should be observed before
merging to production:

1. The version should be updated in all relevant places
   (See [Version](#plugin-version)).
2. The changelog should be updated in `./deploy/update.json`.
3. Tag `master` with the new version number.
4. Fast-forward `production` to this commit of `master`.

## Plugin version

The version of the plugin is currently stored in several files:

1. The top-level plugin file, `php/src/the-shorthand-editor.php`
2. The `Plugin.php`, `php/src/lib/Plugin.php`
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

The top-level `docker-compose.yml` file brings up a local WordPress stack at
`http://localhost:4577/wordpress`.

```bash
pnpm build

docker compose up
```

Interoperation with Shorthand requires SSL, so the container should be proxied,
although this is not set up in this repository. The home and site URLs set up by
the container assume the proxy is at `https://localhost:9443/wordpress`.

To generate the `meta.json` file, run

```bash
METAFILE=1 pnpm build
```

The `docker-compose.yml` file also contains a profile with an older combination
of WordPress and PHP, `wp60_php72`. This opens up a WordPress instance on port 4578.

### Distribution

The plugin can be bundled as a ZIP file. This is the most convenient
way to share the file for testing or distribution.

To generate a ZIP file of the WordPress plugin for distribution, run

```bash
NODE_ENV=production pnpm bundle
```

When bundling, the `meta.json` file will be generated in
the `dist` folder when `METAFILE=1` is specified.
