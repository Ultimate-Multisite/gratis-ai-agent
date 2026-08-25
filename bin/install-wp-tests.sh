#!/usr/bin/env bash

if [ "$#" -lt 3 ]; then
	printf 'usage: %s <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]\n' "$0" >&2
	exit 1
fi

set -euo pipefail

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

TMPDIR="${TMPDIR:-/tmp}"
TMPDIR="${TMPDIR%/}"
TMPDIR="${TMPDIR:-/tmp}"
WP_TESTS_DIR="${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-$TMPDIR/wordpress}"
CACHE_ROOT="${WP_PHPUNIT_CACHE_DIR:-$(dirname "$WP_CORE_DIR")}"
VERSION_KEY="${WP_VERSION//[^[:alnum:]._-]/-}"
VERSION_KEY="${VERSION_KEY:-trunk}"
LOCK_DIR="$CACHE_ROOT/.wordpress-phpunit-${VERSION_KEY}.lock"
LOCK_ACQUIRED=false
WORK_DIR=''
CORE_STAGING=''
TESTS_STAGING=''
WP_TESTS_TAG=''
ARCHIVE_NAME=''

download() {
	local url="$1"
	local destination="$2"

	if command -v curl >/dev/null 2>&1; then
		curl --fail --location --silent --show-error "$url" --output "$destination"
		return 0
	fi

	if command -v wget >/dev/null 2>&1; then
		wget --quiet --output-document="$destination" "$url"
		return 0
	fi

	printf 'Neither curl nor wget is available for downloading WordPress test files.\n' >&2
	return 1
}

cleanup() {
	local exit_code="$?"

	if [ -n "$CORE_STAGING" ] && [ -d "$CORE_STAGING" ]; then
		rm -rf "$CORE_STAGING"
	fi

	if [ -n "$TESTS_STAGING" ] && [ -d "$TESTS_STAGING" ]; then
		rm -rf "$TESTS_STAGING"
	fi

	if [ -n "$WORK_DIR" ] && [ -d "$WORK_DIR" ]; then
		rm -rf "$WORK_DIR"
	fi

	if [ "$LOCK_ACQUIRED" = true ] && [ -d "$LOCK_DIR" ]; then
		rm -rf "$LOCK_DIR"
	fi

	exit "$exit_code"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

acquire_lock() {
	local owner_pid=''

	while ! mkdir "$LOCK_DIR" 2>/dev/null; do
		if [ -f "$LOCK_DIR/pid" ]; then
			read -r owner_pid <"$LOCK_DIR/pid" || owner_pid=''
			if [[ "$owner_pid" =~ ^[0-9]+$ ]] && ! kill -0 "$owner_pid" 2>/dev/null; then
				printf 'Removing stale PHPUnit cache lock owned by process %s.\n' "$owner_pid"
				rm -rf "$LOCK_DIR"
				continue
			fi
		fi

		printf 'Waiting for another process to provision WordPress PHPUnit cache %s.\n' "$VERSION_KEY"
		sleep 1
	done

	printf '%s\n' "$$" >"$LOCK_DIR/pid"
	LOCK_ACQUIRED=true
	return 0
}

is_valid_core() {
	local directory="$1"

	[ -f "$directory/wp-settings.php" ]
	return $?
}

is_valid_test_suite() {
	local directory="$1"

	[ -f "$directory/includes/functions.php" ]
	return $?
}

create_staging_dir() {
	local variable_name="$1"
	local target_dir="$2"
	local parent_dir=''
	local base_name=''

	parent_dir="$(dirname "$target_dir")"
	base_name="$(basename "$target_dir")"
	mkdir -p "$parent_dir"
	printf -v "$variable_name" '%s' "$(mktemp -d "$parent_dir/.${base_name}.staging.XXXXXX")"
	return 0
}

promote_staging_dir() {
	local variable_name="$1"
	local target_dir="$2"
	local staging_dir="${!variable_name}"
	local backup_dir="${target_dir}.previous.$$"

	if [ -e "$target_dir" ]; then
		rm -rf "$backup_dir"
		mv "$target_dir" "$backup_dir"
	fi

	if ! mv "$staging_dir" "$target_dir"; then
		if [ -e "$backup_dir" ]; then
			mv "$backup_dir" "$target_dir"
		fi
		return 1
	fi

	printf -v "$variable_name" '%s' ''
	rm -rf "$backup_dir"
	return 0
}

set_wp_tests_tag() {
	local latest_file="$WORK_DIR/wp-latest.json"
	local latest_version=''

	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
		WP_TESTS_TAG="branches/${WP_VERSION%-*}"
		return 0
	fi

	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
		WP_TESTS_TAG="branches/$WP_VERSION"
		return 0
	fi

	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
		if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.0$ ]]; then
			WP_TESTS_TAG="tags/${WP_VERSION%??}"
		else
			WP_TESTS_TAG="tags/$WP_VERSION"
		fi
		return 0
	fi

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		WP_TESTS_TAG='trunk'
		return 0
	fi

	download 'https://api.wordpress.org/core/version-check/1.7/' "$latest_file"
	latest_version="$(grep -o '"version":"[^"]*' "$latest_file" | sed 's/"version":"//' | head -1)"
	if [ -z "$latest_version" ]; then
		printf 'Latest WordPress version could not be found.\n' >&2
		return 1
	fi

	WP_TESTS_TAG="tags/$latest_version"
	return 0
}

resolve_archive_name() {
	local latest_file="$WORK_DIR/wp-latest.json"
	local latest_version=''
	local version_escaped=''

	if [ "$WP_VERSION" = 'latest' ]; then
		ARCHIVE_NAME='latest'
		return 0
	fi

	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+ ]]; then
		download 'https://api.wordpress.org/core/version-check/1.7/' "$latest_file"
		if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.0$ ]]; then
			latest_version="${WP_VERSION%??}"
		else
			version_escaped="${WP_VERSION//./\\.}"
			latest_version="$(grep -o '"version":"'"$version_escaped"'[^\"]*' "$latest_file" | sed 's/"version":"//' | head -1)"
		fi

		if [ -n "$latest_version" ]; then
			ARCHIVE_NAME="wordpress-$latest_version"
		else
			ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		return 0
	fi

	ARCHIVE_NAME="wordpress-$WP_VERSION"
	return 0
}

write_test_config() {
	local tests_dir="$1"
	local core_dir="$WP_CORE_DIR"
	local ioption=''

	if [ -f "$tests_dir/wp-tests-config.php" ]; then
		return 0
	fi

	if [[ $(uname -s) == 'Darwin' ]]; then
		ioption='-i.bak'
	else
		ioption='-i'
	fi

	download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$tests_dir/wp-tests-config.php"
	while [[ $core_dir == */ ]]; do
		core_dir="${core_dir%/}"
	done
	sed "$ioption" "s:dirname( __FILE__ ) . '/src/':'$core_dir/':" "$tests_dir/wp-tests-config.php"
	sed "$ioption" "s:__DIR__ . '/src/':'$core_dir/':" "$tests_dir/wp-tests-config.php"
	sed "$ioption" "s/youremptytestdbnamehere/$DB_NAME/" "$tests_dir/wp-tests-config.php"
	sed "$ioption" "s/yourusernamehere/$DB_USER/" "$tests_dir/wp-tests-config.php"
	sed "$ioption" "s/yourpasswordhere/$DB_PASS/" "$tests_dir/wp-tests-config.php"
	sed "$ioption" "s|localhost|${DB_HOST}|" "$tests_dir/wp-tests-config.php"
	return 0
}

install_wp() {
	local archive_file=''

	if is_valid_core "$WP_CORE_DIR"; then
		return 0
	fi

	printf 'Rebuilding incomplete WordPress core cache at %s.\n' "$WP_CORE_DIR"
	create_staging_dir CORE_STAGING "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		rmdir "$CORE_STAGING"
		svn export --quiet https://core.svn.wordpress.org/trunk "$CORE_STAGING"
	else
		resolve_archive_name
		archive_file="$CORE_STAGING/wordpress.tar.gz"
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$archive_file"
		tar --strip-components=1 -zxmf "$archive_file" -C "$CORE_STAGING"
		rm -f "$archive_file"
	fi

	if ! is_valid_core "$CORE_STAGING"; then
		printf 'WordPress core staging directory is incomplete: %s/wp-settings.php is missing.\n' "$CORE_STAGING" >&2
		return 1
	fi

	promote_staging_dir CORE_STAGING "$WP_CORE_DIR"
	return 0
}

install_test_suite() {
	if ! is_valid_test_suite "$WP_TESTS_DIR"; then
		printf 'Rebuilding incomplete WordPress test-library cache at %s.\n' "$WP_TESTS_DIR"
		create_staging_dir TESTS_STAGING "$WP_TESTS_DIR"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$TESTS_STAGING/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$TESTS_STAGING/data"
		write_test_config "$TESTS_STAGING"
		if ! is_valid_test_suite "$TESTS_STAGING"; then
			printf 'WordPress test-library staging directory is incomplete: %s/includes/functions.php is missing.\n' "$TESTS_STAGING" >&2
			return 1
		fi
		promote_staging_dir TESTS_STAGING "$WP_TESTS_DIR"
	fi

	write_test_config "$WP_TESTS_DIR"
	return 0
}

recreate_db() {
	local -a extra=( "$@" )

	mysqladmin drop "$DB_NAME" -f --user="$DB_USER" --password="$DB_PASS" "${extra[@]}"
	create_db "${extra[@]}"
	printf 'Recreated the database (%s).\n' "$DB_NAME"
	return 0
}

create_db() {
	local -a extra=( "$@" )

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" "${extra[@]}"
	return 0
}

install_db() {
	local db_hostname=''
	local db_socket_or_port=''
	local -a extra=()

	if [ "$SKIP_DB_CREATE" = 'true' ]; then
		return 0
	fi

	IFS=':' read -r db_hostname db_socket_or_port <<<"$DB_HOST"
	if [ -n "$db_hostname" ]; then
		if [[ $db_socket_or_port =~ ^[0-9]+$ ]]; then
			extra=( "--host=$db_hostname" "--port=$db_socket_or_port" '--protocol=tcp' )
		elif [ -n "$db_socket_or_port" ]; then
			extra=( "--socket=$db_socket_or_port" )
		else
			extra=( "--host=$db_hostname" '--protocol=tcp' )
		fi
	fi

	if mysql --user="$DB_USER" --password="$DB_PASS" "${extra[@]}" --execute='show databases;' | grep -q "^${DB_NAME}$"; then
		printf 'Reinitializing will delete the existing test database (%s).\n' "$DB_NAME"
		recreate_db "${extra[@]}"
	else
		create_db "${extra[@]}"
	fi
	return 0
}

mkdir -p "$CACHE_ROOT"
acquire_lock
WORK_DIR="$(mktemp -d "$CACHE_ROOT/.wordpress-phpunit-${VERSION_KEY}.XXXXXX")"
set_wp_tests_tag
install_wp
install_test_suite

if ! is_valid_core "$WP_CORE_DIR" || ! is_valid_test_suite "$WP_TESTS_DIR"; then
	printf 'WordPress PHPUnit cache validation failed after provisioning.\n' >&2
	exit 1
fi

install_db
