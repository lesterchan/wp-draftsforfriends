#!/usr/bin/env bash
#
# Run the PHPUnit suite inside wp-env.
#
# Requires Docker to be running. First run takes a few minutes while wp-env
# pulls the WordPress, MySQL and PHPUnit images.
#
#   bin/test.sh            run the suite against the versions in .wp-env.json
#   bin/test.sh --floor    run it against the supported floor instead
#   bin/test.sh --filter X pass extra args straight to phpunit
#
# .wp-env.json pins the latest WordPress, so a plain run only ever covers the
# ceiling. CI matrixes both, and a notice that WordPress 6.0 raises and later
# versions do not has already slipped through once -- reach for --floor before
# pushing anything that touches an admin screen.
set -euo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )/.."

if [ "${1:-}" = "--floor" ]; then
	shift
	export WP_ENV_CORE="WordPress/WordPress#6.0"
	export WP_ENV_PHP_VERSION="7.4"
	echo "Running against the floor: WordPress 6.0 on PHP 7.4."
fi

if ! docker info >/dev/null 2>&1; then
	echo "Docker is not running. Start Docker Desktop and try again." >&2
	exit 1
fi

# Bring the environment up (idempotent).
npx --yes @wordpress/env start

# Dev dependencies live inside the container, so nothing lands in the repo.
npx --yes @wordpress/env run tests-cli --env-cwd=wp-content/plugins/wp-draftsforfriends \
	composer install --no-interaction --no-progress

npx --yes @wordpress/env run tests-cli --env-cwd=wp-content/plugins/wp-draftsforfriends \
	vendor/bin/phpunit "$@"
