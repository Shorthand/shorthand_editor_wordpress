#!/usr/bin/env bash

set -e

: ${NODE_ENV:=production}
: ${API_URL:=https://api.theshorthand.com}
: ${APP_URL:=https://app.theshorthand.com}

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

cp -R "$phpdir/src/" "$stagedir"
rm -rf "$stagedir/public"

esbuilddir="$distdir/build"
mkdir -p "$esbuilddir/public"

# Build the plugin's JavaScript/CSS assets, with output to the dist directory
NODE_ENV="$NODE_ENV" \
WITH_LICENSES=1 \
OUTDIR=dist/build \
	pnpm -C "$plugindir" build

# Copy scripts into staging directory
mkdir -p "$stagedir/public" "$stagedir/third-party"
cp -R "$esbuilddir/public/" "$stagedir/public"
cp -R "$esbuilddir/third-party/" "$stagedir/third-party"

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

define('THESHED_DEFAULT_API_URL', '$API_URL');
define('THESHED_DEFAULT_APP_URL', '$APP_URL');

EOF
fi

# Create the final zip package in the dist directory
(cd "$distdir" && zip -q "$pluginname.zip" -r "$pluginname" -x '*/.*')

# Print out the configuration used for the build
echo NODE_ENV="$NODE_ENV"
if [ "$NODE_ENV" != "production" ]; then
	echo APP_URL="$APP_URL"
	echo API_URL="$API_URL"
fi

echo "Built WordPress plugin package at $distdir/$pluginname.zip"
if [ -n "$METAFILE" ]; then
  echo "Generated metafile at $distdir/meta.json"
fi