#!/usr/bin/env bash
# A throwaway real WordPress in .local/<name>. Usage: setup-wp.sh <name> <port> sqlite|mysql [db host:port]
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
name="$1"; port="$2"; db="$3"; dbhost="${4:-}"
cache="$root/.local/cache"; site="$root/.local/$name"
mkdir -p "$cache"
# WP_VERSION=6.5 for an older release.
release="${WP_VERSION:+wordpress-$WP_VERSION}"; release="${release:-latest}"
[ -f "$cache/$release.zip" ] || curl -sfL "https://wordpress.org/$release.zip" -o "$cache/$release.zip"
rm -rf "$site" "$site.tmp"; mkdir -p "$site"
unzip -q "$cache/$release.zip" -d "$site.tmp"; mv "$site.tmp/wordpress" "$site/web"; rmdir "$site.tmp"
cat > "$site/router.php" <<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . '/web' . $path;
if ( '/' !== $path && is_file( $file ) && ! str_ends_with( $path, '.php' ) ) {
	return false;
}
if ( '/' !== $path && str_ends_with( $path, '.php' ) && is_file( $file ) ) {
	chdir( dirname( $file ) );
	$_SERVER['SCRIPT_NAME']     = $path;
	$_SERVER['SCRIPT_FILENAME'] = $file;
	$_SERVER['PHP_SELF']        = $path;
	require $file;
	return;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/web/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( __DIR__ . '/web' );
require __DIR__ . '/web/index.php';
PHP
wp="wp --path=$site/web --skip-themes=false"
if [ "$db" = sqlite ]; then
  [ -f "$cache/sqlite.zip" ] || curl -sfL https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -o "$cache/sqlite.zip"
  unzip -q "$cache/sqlite.zip" -d "$site/web/wp-content/plugins"
  cp "$site/web/wp-content/plugins/sqlite-database-integration/db.copy" "$site/web/wp-content/db.php"
  sed -i '' "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$site/web/wp-content/plugins/sqlite-database-integration|; s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|" "$site/web/wp-content/db.php"
  $wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check --quiet
else
  mysql -h "${dbhost%%:*}" -P "${dbhost##*:}" -uroot -e "DROP DATABASE IF EXISTS \`$name\`; CREATE DATABASE \`$name\`"
  $wp config create --dbname="$name" --dbuser=root --dbpass= --dbhost="$dbhost" --skip-check --quiet
fi
$wp config set WP_ENVIRONMENT_TYPE local --quiet
$wp config set WP_DEBUG true --raw --quiet
$wp config set WP_DEBUG_LOG true --raw --quiet
$wp config set WP_DEBUG_DISPLAY false --raw --quiet
adminpass="$(openssl rand -hex 16)"
$wp core install --url="http://127.0.0.1:$port" --title="AiFinPay e2e" --admin_user=admin --admin_password="$adminpass" --admin_email=admin@example.test --skip-email --quiet
printf '%s' "$adminpass" > "$site/admin-pass"
$wp rewrite structure '/%postname%/' --quiet
$wp eval 'echo "WordPress ", get_bloginfo("version"), " on ", ( defined("DB_ENGINE") ? DB_ENGINE : $GLOBALS["wpdb"]->db_server_info() ), PHP_EOL;'
