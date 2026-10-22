#!/usr/bin/env bash
# Sets up the WordPress PHPUnit test suite outside the repository.
#
# Everything lands in ~/.cache/mail-vitals-wp-tests/<version>: a separate copy
# of WordPress core (so tests never write into a real site) and the test
# library, with "current" pointing at the latest set up. The test database
# must already exist and must not be the site's own database: the suite
# drops every table in it that carries the test prefix.
#
# Usage: bin/install-wp-tests.sh [wp-version]
#
# Credentials come from MAIL_VITALS_TEST_DB_NAME / _USER / _PASSWORD / _HOST,
# or, for whichever is unset, from DB_NAME / DB_USER / DB_PASSWORD / DB_HOST in
# ~/.mailvitals-test-db.env (override with MAIL_VITALS_TEST_DB_ENV). That file
# lives outside the repository and must stay there.
#
# Use a database user that can reach the test database and nothing else. The
# checks below refuse the site's own database and user as a second line of
# defence, but only missing privileges make a mistake physically harmless.
#
# MAIL_VITALS_SITE_PATH (default: /var/www/html) is read only to download the
# matching WordPress version and to know which database and user to refuse.

set -euo pipefail

SITE="${MAIL_VITALS_SITE_PATH:-/var/www/html}"
WP_VERSION="${1:-$(wp core version --path="$SITE")}"
ROOT="$HOME/.cache/mail-vitals-wp-tests"
BASE="$ROOT/$WP_VERSION"
CORE="$BASE/wordpress"
LIB="$BASE/tests-lib"

ENV_FILE="${MAIL_VITALS_TEST_DB_ENV:-$HOME/.mailvitals-test-db.env}"
FILE_DB_NAME=""; FILE_DB_USER=""; FILE_DB_PASSWORD=""; FILE_DB_HOST=""

# Read KEY=value lines without executing the file.
if [ -f "$ENV_FILE" ]; then
	while IFS='=' read -r key value || [ -n "$key" ]; do
		case "$key" in
			DB_NAME) FILE_DB_NAME="$value" ;;
			DB_USER) FILE_DB_USER="$value" ;;
			DB_PASSWORD) FILE_DB_PASSWORD="$value" ;;
			DB_HOST) FILE_DB_HOST="$value" ;;
		esac
	done < "$ENV_FILE"
fi

DB_NAME="${MAIL_VITALS_TEST_DB_NAME:-$FILE_DB_NAME}"
DB_USER="${MAIL_VITALS_TEST_DB_USER:-$FILE_DB_USER}"
DB_PASSWORD="${MAIL_VITALS_TEST_DB_PASSWORD:-$FILE_DB_PASSWORD}"
DB_HOST="${MAIL_VITALS_TEST_DB_HOST:-${FILE_DB_HOST:-localhost}}"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
	echo "Missing test database credentials. Set MAIL_VITALS_TEST_DB_* or create $ENV_FILE." >&2
	exit 1
fi

SITE_DB="$(wp config get DB_NAME --path="$SITE")"
SITE_DB_USER="$(wp config get DB_USER --path="$SITE")"

if [ "$DB_NAME" = "$SITE_DB" ]; then
	echo "Refusing: the test database ($DB_NAME) is the site's database. The test suite drops its tables." >&2
	exit 1
fi

if [ "$DB_USER" = "$SITE_DB_USER" ]; then
	echo "Refusing: $DB_USER is the site's database user. Use a user that can reach only the test database." >&2
	exit 1
fi

mkdir -p "$BASE"

if [ ! -f "$CORE/wp-settings.php" ]; then
	echo "Downloading WordPress $WP_VERSION core to $CORE"
	wp core download --version="$WP_VERSION" --path="$CORE" --force
fi

if [ ! -f "$LIB/includes/functions.php" ]; then
	echo "Exporting the test library for $WP_VERSION"
	svn export --quiet --force "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/includes/" "$LIB/includes"
	svn export --quiet --force "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/data/" "$LIB/data"
fi

CONFIG="$LIB/wp-tests-config.php"
umask 077
cat > "$CONFIG" <<PHP
<?php
define( 'ABSPATH', '$CORE/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_MULTISITE', false );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASSWORD' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'mvtests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

ln -sfn "$BASE" "$ROOT/current"
echo "Ready. Run: composer test"
