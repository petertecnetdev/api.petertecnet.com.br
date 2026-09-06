#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${1:-dump}"
APP_DIR="${2:-/var/www/api.petertecnet.com.br}"

if [[ "$MODE" == /* ]]; then
  APP_DIR="$MODE"
  MODE="dump"
fi

fail() {
  printf 'backup export error: %s\n' "$*" >&2
  exit 1
}

command -v php >/dev/null 2>&1 || fail "php is not available"
[[ -f "$APP_DIR/artisan" && -f "$APP_DIR/bootstrap/app.php" ]] || fail "Laravel application not found in $APP_DIR"
[[ -f "$APP_DIR/vendor/autoload.php" ]] || fail "Laravel dependencies are not installed"

if [[ "$MODE" == "server-version" ]]; then
  cd "$APP_DIR"
  exec php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $connection = $app->make("db")->connection();
    $row = $connection->selectOne("SELECT VERSION() AS version");
    echo (string) ($row->version ?? "unknown"), PHP_EOL;
  '
fi

[[ "$MODE" == "dump" ]] || fail "unsupported mode: $MODE"
command -v mysqldump >/dev/null 2>&1 || fail "mysqldump is not available"
command -v gzip >/dev/null 2>&1 || fail "gzip is not available"

mapfile -t DB_CFG < <(
  cd "$APP_DIR"
  php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $name = config("database.default");
    $cfg = config("database.connections.".$name, []);
    foreach (["driver", "host", "port", "database", "username", "password", "unix_socket", "charset"] as $key) {
        echo base64_encode((string) ($cfg[$key] ?? "")), PHP_EOL;
    }
  '
)

[[ "${#DB_CFG[@]}" -eq 8 ]] || fail "could not read database configuration"
decode64() { printf '%s' "$1" | base64 -d; }

DB_DRIVER="$(decode64 "${DB_CFG[0]}")"
DB_HOST="$(decode64 "${DB_CFG[1]}")"
DB_PORT="$(decode64 "${DB_CFG[2]}")"
DB_NAME="$(decode64 "${DB_CFG[3]}")"
DB_USER="$(decode64 "${DB_CFG[4]}")"
DB_PASSWORD="$(decode64 "${DB_CFG[5]}")"
DB_SOCKET="$(decode64 "${DB_CFG[6]}")"
DB_CHARSET="$(decode64 "${DB_CFG[7]}")"

[[ "$DB_DRIVER" == "mysql" || "$DB_DRIVER" == "mariadb" ]] || fail "unsupported database driver: $DB_DRIVER"
[[ -n "$DB_NAME" && -n "$DB_USER" ]] || fail "database credentials are incomplete"

connection_args=()
if [[ -n "$DB_SOCKET" ]]; then
  connection_args+=(--socket="$DB_SOCKET")
else
  connection_args+=(--host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}")
fi

dump_args=(
  --single-transaction
  --quick
  --skip-lock-tables
  --triggers
  --hex-blob
  --default-character-set="${DB_CHARSET:-utf8mb4}"
  --user="$DB_USER"
)

if mysqldump --help 2>&1 | grep -q -- '--no-tablespaces'; then
  dump_args+=(--no-tablespaces)
fi

MYSQL_PWD="$DB_PASSWORD" mysqldump \
  "${connection_args[@]}" \
  "${dump_args[@]}" \
  "$DB_NAME" \
  | gzip -9
