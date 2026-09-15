#!/usr/bin/env bash
# Run the integration suite on each "wordpress:php" pair in MATRIX.
#
#   bin/wp-matrix.sh
#   MATRIX="6.0.11:7.4 6.8.3:8.3" bin/wp-matrix.sh
set -euo pipefail
cd "$(dirname "$0")/.."

for pair in ${MATRIX:-"6.0.11:7.4 6.4.7:8.1 6.8.3:8.3"}; do
	echo "== WordPress ${pair%%:*} / PHP ${pair##*:}"
	export WP_ENV_CORE="https://wordpress.org/wordpress-${pair%%:*}.zip"
	export WP_ENV_PHP_VERSION="${pair##*:}"
	pnpm --silent wp-env start --config .wp-env.test.json --update
	pnpm --silent test:php:integration
	pnpm --silent wp-env cleanup --force --config .wp-env.test.json
done
