#!/usr/bin/env node

import esbuild from "esbuild";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { WORDPRESS_PLUGIN_BUILD_OPTIONS } from "./esbuild.options.js";

/**
 * This is the build driver script for the TypeScript components of the
 * WordPress plugin.
 *
 * The following environment variables affect its behaviour:
 * - `NODE_ENV`: The build environment. Defaults to "production".
 * - `HOT_RELOAD`: If set, enables watch mode for type checking.
 * - `NO_MINIFY`: If set, disables minification, without changing the output filename.
 * - `WITH_LICENSES`: If set, gathers and includes third-party license files in the output.
 * - `METAFILE`: If set, generates an esbuild metafile for analyzing bundle contents.
 * - `OUTDIR`: The output directory for the built assets, relative to this file.
 *       Defaults to the current directory.
 *
 * It produces the following files in the output directory:
 * - `public/scripts/post.min.js`: The bundled JavaScript for the post editor.
 * - `public/scripts/post.min.js.map`: The source map for the bundled JavaScript (not in production).
 * - `third-party/`: A directory containing third-party license files (only if WITH_LICENSES is set).
 * - `third-party/summary.txt`: A summary of third-party dependencies and their licenses (only if WITH_LICENSES is set).
 * - `meta.json`: An esbuild metafile describing the bundle contents (only if METAFILE is set).
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));

esbuild
  .build(WORDPRESS_PLUGIN_BUILD_OPTIONS)
  .then((result) => {
    if (WORDPRESS_PLUGIN_BUILD_OPTIONS.metafile) {
      fs.writeFileSync(
        path.join(
          path.resolve(__dirname, process.env.OUTDIR || "."),
          "meta.json",
        ),
        JSON.stringify(result.metafile, null, 2),
      );
    }
  })
  .catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
