#!/usr/bin/env bash
# Run the PHP unit suite in the CircleCI image for each PHP version given.
# The image matches the `wp-plugin-php-unit` job, so a pass here is a pass in
# CI. Each version keeps its own vendor in a Docker volume; php/vendor on the
# host is not touched.
#
#   bin/php-unit.sh                  # every version CI runs
#   bin/php-unit.sh 8.4              # one version
set -euo pipefail
cd "$(dirname "$0")/.."

# Keep in step with the wp-plugin-php-unit matrix in .circleci/prod-config.yml.
versions=("$@")
if [[ ${#versions[@]} -eq 0 ]]; then
	versions=(8.2 8.3 8.4 8.5)
fi

status=0
for version in "${versions[@]}"; do
	echo "== PHP ${version}"
	if ! docker run --rm --user root -e COMPOSER_ALLOW_SUPERUSER=1 \
		-v "${PWD}:/project" -v "s4w-php-unit-vendor-${version}:/project/php/vendor" \
		-w /project "cimg/php:${version}" \
		bash -c 'composer -d php install --no-interaction --no-scripts --prefer-dist --quiet && composer -d php test'; then
		echo "== FAILED: PHP ${version}" >&2
		status=1
	fi
done
exit "$status"
