#!/usr/bin/env bash
# wp-env afterStart hook: make the plugin usable on first boot.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -d public/scripts ]; then
	echo "public/scripts is missing; run 'pnpm build' so the plugin has its assets." >&2
fi

pnpm --silent wp-env run cli wp plugin activate the-shorthand-editor
pnpm --silent wp-env run cli wp rewrite structure '/%postname%/' --hard
