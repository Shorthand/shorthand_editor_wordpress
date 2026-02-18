# WordPress plugin

The WordPress plugin is called _The Shorthand Editor_, and uses the
short name `the-shorthand-editor` for URLs and the WordPress plugin
directory.

The plugin's slug on the WordPress plugin directory is
`the-shorthand-editor`, and will not change, even if we change
the display name of the plugin.

## Name and version

WordPress parses the header comment in the top-level plugin file (e.g. `plugin/plugin.php`)
to determine the name, version and other metadata of the plugin.

The metadata needs to be kept in sync in the following places:

1. The local server `site/src/index.ts` maps the local script builds into
   the plugin's directory on the local WordPress site, which must match
   the plugin's short name.
2. CircleCI builds and saves the plugin as an artefact of the build stage,
   configured in `.circleci/config.yml`. It uses the name of the ZIP file.
3. The bundling script, `plugins/wordpress/bin/bundle.sh` uses the short
   name of the plugin in order to build the ZIP file.
4. The file `plugins/wordpress/php/src/lib/Core/Version.php` reflects the
   meta data for use by the plugin itself.
5. The name of the plugin file `plugins/wordpress/php/src/the-shorthand-editor.php`
   should be the short name of the plugin.
6. The docker compose file `docker-wp.yml` maps the local plugin source
   to the plugin's directory in the local WordPress site. The plugin
   directory name should be the short name of the plugin.

## Development

The plugin is written in PHP and Typescript. No additional PHP tooling is
needed to run the plugin locally (see below). It supports ESBuild metafiles.

It can be bundled as a ZIP for distribution or remote testing.

The WordPress plugin is built during `pnpm build`, and its ZIP and meta.json
file are stored as artefacts on Circle CI.

### PHP and Composer

The source for the plugin can be bundled and distributed as-is (see
[Distribution](#distribution)).

Other tasks such as dependency management or compatibility checking require
the `composer` tool, which may be installed via homebrew

```bash
brew install compoer
```

Dependency libraries are pinned in the `composer.lock` file, but may be
updated by running `composer update` from the `php` directory (see [Third-
party dependencies](#third-party-dependencies) for more information).

Note: the use of `magento/php-compatibility-fork` works around a bug in `phpcompatibility/php-compatibility`
version 9.3.5 which, while fixed in version 10, would cause a conflict with other
dependencies over `squizzlabs/php_codesniffer`.

#### Minimum PHP version

The plugin supports a minimum PHP version of 7.2. This may present some
hurdles when developing in the presence of later language features.
Therefore, consider the following utilites defined in `composer.json`
which may be run from within the `php` directory with `composer` installed.

After making PHP source modifications, consider downcompiling the source
to PHP language version 7.2. Note that this modifies source in place, so
treat this command as if it were destructive and manage the risk, e.g. by
adding your `php/src` directory to your Git index.

```bash
composer run-script downcompile
```

Before committing your PHP changes, check the source against the
compatibility rules for language version 7.2.

```bash
composer run-script check-7.2
```

#### Third-party dependencies

Best practices for WordPress plugins recommend bundling third-party library
dependencies in a custom namespace to avoid version conflicts between plugins.

The plugin dependends on the `firebase/php-jwt` library for signin JWTs. To
incorporate an updated version of the library (and any others), run the
following command from the `php` directory

```bash
composer run-script prefix-dependencies
```

This will additionally downcompile the source to PHP 7.2, namespace it under
the `Shorthand\Vendor` namespace, and copy it into the `src/vendor_prefixed`
directory. These files are checked in, as they are pinned, and are distributed
as a party of the plugin under their declared licenses.

### Local development

When doing local development work, a local WordPress instance can be spun up
at `http://localhost:4577/wordpress` using

```bash
pnpm build

docker compose up
```

To generate the `meta.json` file, run

```bash
METAFILE=1 pnpm build
```

The `docker-compose.yml` file also contains a profile with an older combination
of WordPress and PHP, `wp60_php72`. This opens up a WordPress instance on port 4578.

### Distribution

The plugin can be bundled as a ZIP file. This is the most convenient
way to share the file for testing or distribution.

To generate the a ZIP file of the WordPress plugin for distribution run

```bash
NODE_ENV=production pnpm bundle
```

When bundling, the `meta.json` file will be generated in
the `dist` folder when `METAFILE=1` is specified.
