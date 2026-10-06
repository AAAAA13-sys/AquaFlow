#!/bin/sh
#
# AquaFlow test container entrypoint.
#
# Runs PHPUnit against its own database (DB_DATABASE, default aquaflow_test) so a
# test run can never touch application data. MySQL only creates the databases
# listed in MYSQL_DATABASE on first initialisation, so the test database is
# created here, before PHPUnit boots.
#
# The container idles (CMD sleep infinity) so repeated runs are cheap:
#   docker compose exec test php artisan test --filter=US03

set -e

DB_NAME="${DB_DATABASE:-aquaflow_test}"

echo "[test] waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306} ..."
attempt=0
until php -r '
$dsn = sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "db", getenv("DB_PORT") ?: "3306");
try {
    new PDO($dsn, getenv("DB_USERNAME") ?: "root", getenv("DB_PASSWORD") ?: "");
    exit(0);
} catch (Throwable $e) {
    exit(1);
}
' 2>/dev/null; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo "[test] MySQL unreachable after 60 attempts - giving up." >&2
    exit 1
  fi
  sleep 2
done

echo "[test] ensuring database '${DB_NAME}' exists ..."
DB_DATABASE="$DB_NAME" php -r '
$dsn = sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "db", getenv("DB_PORT") ?: "3306");
$pdo = new PDO($dsn, getenv("DB_USERNAME") ?: "root", getenv("DB_PASSWORD") ?: "");
$name = getenv("DB_DATABASE") ?: "aquaflow_test";
$pdo->exec(sprintf(
    "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    $name
));
echo "[test] database ready: {$name}\n";
'

# The image bakes a config cache; clear it so compose env vars apply.
php artisan config:clear >/dev/null 2>&1 || true

# Laravel bootstraps LoadEnvironmentVariables for every test. .dockerignore keeps
# .env out of the image (it holds secrets), but when the file is missing phpdotenv
# emits a PHP warning that PHPUnit surfaces on every single test - 100+ spurious
# warnings. The test container holds no secrets (phpunit.xml and the compose
# environment carry the real config), so a minimal placeholder is all that is
# needed to keep the run clean. This mirrors CI, which copies .env.example.
if [ ! -f .env ]; then
  printf 'APP_ENV=testing\n' > .env
  echo "[test] wrote a minimal .env (real config comes from phpunit.xml + compose)"
fi

echo "[test] ready - run:  docker compose exec test php artisan test"
exec "$@"
