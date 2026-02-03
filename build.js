#!/usr/bin/env node

import esbuild from "esbuild";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { WORDPRESS_PLUGIN_BUILD_OPTIONS } from "./esbuild.options.js";

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
