#!/usr/bin/env bash

set -e

: "${NODE_ENV:=production}"
: "${API_URL:=https://api.shorthand.com}"
: "${APP_URL:=https://app.shorthand.com}"
: "${UPDATE_URL:=https://shorthand.com/plugins/wp/the-shorthand-editor/update.json}"


pluginname=the-shorthand-editor

bindir=$(dirname "$0")
plugindir="$bindir/.."
distdir="$plugindir/dist"
phpdir="$plugindir/php"

rm -rf "$distdir"
mkdir -p "$distdir"

stagedir="$distdir/$pluginname"
mkdir -p "$stagedir"

cp "$plugindir/LICENSE" "$stagedir/license.txt"

cp -R "$phpdir/src/." "$stagedir"

# Remove any build artifacts from previous builds
rm -rf "$stagedir/public"
rm -rf "$stagedir/third-party"
rm -f "$stagedir/meta.json"

# Build the plugin's JavaScript/CSS assets, with output to the dist directory
NODE_ENV="$NODE_ENV" \
WITH_LICENSES=1 \
OUTDIR=$stagedir \
	pnpm -C "$plugindir" build

# Create a file of overrides for non-production environments
if [ "$NODE_ENV" != "production" ]; then
	echo "Creating sh-config.php for non-production environments"
	mkdir -p "$stagedir/lib/Plugin"
	cat > "$stagedir/lib/Plugin/sh-config.php" <<EOF
<?php
// This file is auto-generated during the build process.

if (!defined('ABSPATH')) {
	exit;
}

define('THESHED_UPDATE_URL', '$UPDATE_URL');
define('THESHED_API_URL', '$API_URL');
define('THESHED_APP_URL', '$APP_URL');

EOF
fi

# Create the final zip package in the dist directory
(cd "$distdir" && zip -q "$pluginname.zip" -r "$pluginname" -x '*/.*')

# Print out the configuration used for the build
echo NODE_ENV="$NODE_ENV"
if [ "$NODE_ENV" != "production" ]; then
	echo APP_URL="$APP_URL"
	echo API_URL="$API_URL"
	echo UPDATE_URL="$UPDATE_URL"
fi

echo "Built WordPress plugin package at $distdir/$pluginname.zip"
if [ -n "$METAFILE" ]; then
  echo "Generated metafile at $distdir/meta.json"
fi