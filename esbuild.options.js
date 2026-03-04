import { typecheckPlugin } from "@jgoz/esbuild-plugin-typecheck";
import copyLicensesPlugin from "esbuild-plugin-copy-licenses";
import { postcssModules, sassPlugin } from "esbuild-sass-plugin";
import stdlibBrowser from "node-stdlib-browser";
import stdlibPlugin from "node-stdlib-browser/helpers/esbuild/plugin";
import path from "path";
import { fileURLToPath } from "url";

/**
 * This file defines the esbuild options for building the WordPress plugin.
 * See its driving script ./build.js for more details.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const nodeEnv = process.env.NODE_ENV || "production";
const watch = process.env.HOT_RELOAD || false;
const minify = !Boolean(process.env.NO_MINIFY);
const withLicenses = Boolean(process.env.WITH_LICENSES);
const withMetaFile = Boolean(process.env.METAFILE);
const outdir = path.resolve(__dirname, process.env.OUTDIR || ".");
const stdlib = {
  ...stdlibBrowser,
  net: fileURLToPath(import.meta.resolve("node-stdlib-browser/mock/net")),
};

export const WORDPRESS_PLUGIN_BUILD_OPTIONS = {
  entryNames: `[name].min`,
  entryPoints: {
    post: path.resolve(__dirname, "src/post-shorthand-story.entry.js"),
  },
  platform: "browser",
  target: "es2015",
  sourcemap: nodeEnv !== "production",
  outdir: path.join(outdir, "public/scripts"),
  bundle: true,
  metafile: withMetaFile,
  inject: [
    fileURLToPath(
      import.meta.resolve("node-stdlib-browser/helpers/esbuild/shim"),
    ),
  ],
  tsconfig: path.resolve(__dirname, "tsconfig.json"),
  minify: minify,
  define: {
    "process.env.NODE_ENV": JSON.stringify(nodeEnv || "production"),
    define: "undefined",
  },
  external: [
    "*.png",
    "*.svg",
    "*.jpg",
    "*.woff",
    "*.eot",
    "*.woff2",
    "*.ttf",
    "*.eot?#iefix",
    "canvas",
  ],
  plugins: [
    ...(withLicenses
      ? [
          copyLicensesPlugin({
            copyLicenseFiles: {
              enabled: true,
              directoryPath: path.join(outdir, "third-party"),
            },
            summaryFile: {
              enabled: true,
              filePath: path.join(outdir, "third-party", "summary.txt"),
            },
          }),
        ]
      : []),
    sassPlugin({
      filter: /\.module\.scss$/,
      transform: postcssModules({
        basedir: "cwd",
        localsConvention: "camelCase",
      }),
    }),
    sassPlugin({
      quietDeps: true,
      cssImports: true,
    }),
    stdlibPlugin(stdlib),
    typecheckPlugin({
      configFile: path.resolve(__dirname, "tsconfig.json"),
      watch,
    }),
  ],
};
