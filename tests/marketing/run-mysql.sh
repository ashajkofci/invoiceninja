#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
: "${PHP_BIN:=php}"
export MARKETING_DB_PORT="${MARKETING_DB_PORT:-13309}"
[[ "$MARKETING_DB_PORT" =~ ^[0-9]+$ ]] || exit 2
(( MARKETING_DB_PORT > 0 && MARKETING_DB_PORT < 65536 )) || exit 2
docker version >/dev/null
marketing_project="invoiceninja-marketing-test-$$"
marketing_tmp="$(mktemp -d)"
compose=(docker compose -p "$marketing_project" -f tests/marketing/compose.yml)
cleanup() {
  "${compose[@]}" down --volumes --remove-orphans
  rm -rf "$marketing_tmp"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait
"${compose[@]}" exec -T -e MYSQL_PWD=marketing_test_only mysql mysql -uroot marketing_test < database/schema/mysql-schema.sql
export APP_ENV=testing APP_KEY=base64:bW1tbW1tbW1tbW1tbW1tbW1tbW1tbW1tbW1tbW1tbW0=
export APP_CONFIG_CACHE="$marketing_tmp/config.php"
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_HOST1=127.0.0.1
export DB_PORT="$MARKETING_DB_PORT" DB_PORT1="$MARKETING_DB_PORT"
export DB_DATABASE=marketing_test DB_DATABASE1=marketing_test DB_USERNAME=root DB_USERNAME1=root
export DB_PASSWORD=marketing_test_only DB_PASSWORD1=marketing_test_only DB_URL=''
export CACHE_DRIVER=array CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_URL=http://127.0.0.1:18090 NINJA_ENVIRONMENT=selfhost MARKETING_MYSQL=1
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force
"$PHP_BIN" vendor/bin/phpunit tests/Feature/MarketingApiTest.php --do-not-cache-result
