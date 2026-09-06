#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CONFIG_FILE="${PETERTECNET_BACKUP_CONFIG:-/etc/petertecnet-backup/backup.env}"
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

APP_DIR="${APP_DIR:-/var/www/api.petertecnet.com.br}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/petertecnet/database}"
AGE_RECIPIENT_FILE="${AGE_RECIPIENT_FILE:-/etc/petertecnet-backup/age-recipient.txt}"
LOCAL_DAILY_DAYS="${LOCAL_DAILY_DAYS:-14}"
LOCAL_PREDEPLOY_DAYS="${LOCAL_PREDEPLOY_DAYS:-14}"
LOCAL_MANUAL_DAYS="${LOCAL_MANUAL_DAYS:-30}"
LOCAL_WEEKLY_DAYS="${LOCAL_WEEKLY_DAYS:-70}"
LOCAL_MONTHLY_DAYS="${LOCAL_MONTHLY_DAYS:-400}"
RESTORE_TEST="${RESTORE_TEST:-1}"
OFFSITE_RCLONE_REMOTE="${OFFSITE_RCLONE_REMOTE:-}"
CRITICAL_TABLES="${CRITICAL_TABLES:-users establishments}"
MYSQL_DUMP_INCLUDE_ROUTINES="${MYSQL_DUMP_INCLUDE_ROUTINES:-0}"
MYSQL_DUMP_INCLUDE_EVENTS="${MYSQL_DUMP_INCLUDE_EVENTS:-0}"
LOCK_FILE="${LOCK_FILE:-/run/lock/petertecnet-db-backup.lock}"

log() {
  printf '[%s] %s\n' "$(date --iso-8601=seconds)" "$*"
}

die() {
  log "ERROR: $*" >&2
  exit 1
}

need() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

latest_backup() {
  local kind="${1:-daily}"
  local dir="$BACKUP_ROOT/$kind"
  [[ -d "$dir" ]] || return 1
  find "$dir" -type f -name '*.age' -printf '%T@ %p\n' 2>/dev/null \
    | sort -nr \
    | head -n1 \
    | cut -d' ' -f2-
}

if [[ "${1:-}" == "latest" ]]; then
  latest_backup "${2:-daily}" || exit 1
  exit 0
fi

KIND="${1:-daily}"
case "$KIND" in
  daily|pre-deploy|manual) ;;
  *) die "Usage: $0 [daily|pre-deploy|manual|latest [kind]]" ;;
esac

[[ -d "$APP_DIR" ]] || die "Application directory not found: $APP_DIR"
[[ -f "$APP_DIR/artisan" && -f "$APP_DIR/bootstrap/app.php" ]] || die "Laravel application not found in $APP_DIR"
[[ -s "$AGE_RECIPIENT_FILE" ]] || die "Encryption recipient missing: $AGE_RECIPIENT_FILE"

need php
need age
need gzip
need sha256sum
need base64
need flock
need python3

mkdir -p "$(dirname "$LOCK_FILE")" "$BACKUP_ROOT"
exec 9>"$LOCK_FILE"
flock -w 900 9 || die "Another database backup is already running"

mapfile -t DB_CFG < <(
  cd "$APP_DIR"
  php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $name = config("database.default");
    $cfg = config("database.connections.".$name, []);
    foreach (["driver","host","port","database","username","password","unix_socket"] as $key) {
        echo base64_encode((string)($cfg[$key] ?? "")), PHP_EOL;
    }
  '
)

[[ "${#DB_CFG[@]}" -eq 7 ]] || die "Could not read database configuration from Laravel"

decode64() { printf '%s' "$1" | base64 -d; }
DB_DRIVER="$(decode64 "${DB_CFG[0]}")"
DB_HOST="$(decode64 "${DB_CFG[1]}")"
DB_PORT="$(decode64 "${DB_CFG[2]}")"
DB_NAME="$(decode64 "${DB_CFG[3]}")"
DB_USER="$(decode64 "${DB_CFG[4]}")"
DB_PASSWORD="$(decode64 "${DB_CFG[5]}")"
DB_SOCKET="$(decode64 "${DB_CFG[6]}")"

[[ -n "$DB_NAME" && -n "$DB_DRIVER" ]] || die "Database configuration is incomplete"
case "$DB_DRIVER" in
  mysql|mariadb) need mysqldump; need mysql ;;
  pgsql) need pg_dump; need pg_restore; need psql ;;
  *) die "Unsupported database driver: $DB_DRIVER" ;;
esac

STAMP="$(date '+%Y%m%dT%H%M%S%z')"
YEAR="$(date '+%Y')"
MONTH="$(date '+%m')"
DAY_OF_MONTH="$(date '+%d')"
WEEKDAY="$(date '+%u')"
HOST_SHORT="$(hostname -s | tr -c 'A-Za-z0-9._-' '-')"
DEST_DIR="$BACKUP_ROOT/$KIND/$YEAR/$MONTH"
mkdir -p "$DEST_DIR"

BASE="petertecnet-${KIND}-${HOST_SHORT}-${STAMP}"
PLAIN=""
ENCRYPTED=""
MANIFEST="$DEST_DIR/$BASE.manifest.json"
RESTORE_DB=""
TMP_DIR="$(mktemp -d /tmp/petertecnet-db-backup.XXXXXX)"

cleanup() {
  local exit_code=$?
  if [[ -n "$RESTORE_DB" && "$DB_DRIVER" =~ ^(mysql|mariadb)$ ]]; then
    mysql_admin_exec "DROP DATABASE IF EXISTS \`$RESTORE_DB\`" >/dev/null 2>&1 || true
  elif [[ -n "$RESTORE_DB" && "$DB_DRIVER" == "pgsql" ]]; then
    pg_admin_exec "DROP DATABASE IF EXISTS \"$RESTORE_DB\"" >/dev/null 2>&1 || true
  fi
  [[ -n "$PLAIN" ]] && rm -f "$PLAIN" || true
  rm -rf "$TMP_DIR"
  exit "$exit_code"
}
trap cleanup EXIT

mysql_client_args=()
if [[ -n "$DB_SOCKET" ]]; then
  mysql_client_args+=(--socket="$DB_SOCKET")
else
  mysql_client_args+=(-h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}")
fi

MYSQL_ADMIN_MODE=""

resolve_mysql_admin() {
  [[ -n "$MYSQL_ADMIN_MODE" ]] && return 0

  if [[ -n "${RESTORE_MYSQL_ADMIN_CNF:-}" ]]; then
    mysql --defaults-extra-file="$RESTORE_MYSQL_ADMIN_CNF" -e "SELECT 1" >/dev/null
    MYSQL_ADMIN_MODE="cnf"
    return 0
  fi

  if [[ -n "${RESTORE_DB_ADMIN_USER:-}" ]]; then
    MYSQL_PWD="${RESTORE_DB_ADMIN_PASSWORD:-}" mysql \
      -h "${RESTORE_DB_ADMIN_HOST:-${DB_HOST:-127.0.0.1}}" \
      -P "${RESTORE_DB_ADMIN_PORT:-${DB_PORT:-3306}}" \
      -u "$RESTORE_DB_ADMIN_USER" -e "SELECT 1" >/dev/null
    MYSQL_ADMIN_MODE="configured"
    return 0
  fi

  if [[ "${DB_HOST:-localhost}" =~ ^(localhost|127\.0\.0\.1|::1)$ ]] \
    && mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_ADMIN_MODE="root"
    return 0
  fi

  if MYSQL_PWD="$DB_PASSWORD" mysql "${mysql_client_args[@]}" -u "$DB_USER" -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_ADMIN_MODE="app"
    return 0
  fi

  return 1
}

mysql_admin_exec() {
  local sql="$1"
  resolve_mysql_admin || return 1

  case "$MYSQL_ADMIN_MODE" in
    cnf)
      mysql --defaults-extra-file="$RESTORE_MYSQL_ADMIN_CNF" -e "$sql"
      ;;
    configured)
      MYSQL_PWD="${RESTORE_DB_ADMIN_PASSWORD:-}" mysql \
        -h "${RESTORE_DB_ADMIN_HOST:-${DB_HOST:-127.0.0.1}}" \
        -P "${RESTORE_DB_ADMIN_PORT:-${DB_PORT:-3306}}" \
        -u "$RESTORE_DB_ADMIN_USER" -e "$sql"
      ;;
    root)
      mysql -uroot -e "$sql"
      ;;
    app)
      MYSQL_PWD="$DB_PASSWORD" mysql "${mysql_client_args[@]}" -u "$DB_USER" -e "$sql"
      ;;
    *)
      return 1
      ;;
  esac
}

mysql_restore_into() {
  local database="$1"
  resolve_mysql_admin || return 1

  case "$MYSQL_ADMIN_MODE" in
    cnf)
      mysql --defaults-extra-file="$RESTORE_MYSQL_ADMIN_CNF" "$database"
      ;;
    configured)
      MYSQL_PWD="${RESTORE_DB_ADMIN_PASSWORD:-}" mysql \
        -h "${RESTORE_DB_ADMIN_HOST:-${DB_HOST:-127.0.0.1}}" \
        -P "${RESTORE_DB_ADMIN_PORT:-${DB_PORT:-3306}}" \
        -u "$RESTORE_DB_ADMIN_USER" "$database"
      ;;
    root)
      mysql -uroot "$database"
      ;;
    app)
      MYSQL_PWD="$DB_PASSWORD" mysql "${mysql_client_args[@]}" -u "$DB_USER" "$database"
      ;;
    *)
      return 1
      ;;
  esac
}

pg_admin_exec() {
  local sql="$1"
  if [[ -n "${RESTORE_DB_ADMIN_USER:-}" ]]; then
    PGPASSWORD="${RESTORE_DB_ADMIN_PASSWORD:-}" psql \
      -h "${RESTORE_DB_ADMIN_HOST:-${DB_HOST:-127.0.0.1}}" \
      -p "${RESTORE_DB_ADMIN_PORT:-${DB_PORT:-5432}}" \
      -U "$RESTORE_DB_ADMIN_USER" -d "${RESTORE_DB_ADMIN_DATABASE:-postgres}" \
      -v ON_ERROR_STOP=1 -c "$sql"
  elif [[ "${DB_HOST:-localhost}" =~ ^(localhost|127\.0\.0\.1|::1)$ ]]; then
    sudo -u postgres -- psql -v ON_ERROR_STOP=1 -c "$sql"
  else
    return 1
  fi
}

restore_status="skipped"
restored_table_count=0
critical_tables_verified=0

if [[ "$DB_DRIVER" =~ ^(mysql|mariadb)$ ]]; then
  PLAIN="$TMP_DIR/$BASE.sql.gz"
  log "Creating consistent MySQL/MariaDB backup of $DB_NAME"
  mysql_dump_options=(
    --single-transaction
    --quick
    --triggers
    --hex-blob
    --default-character-set=utf8mb4
  )
  if mysqldump --help 2>&1 | grep -q -- '--no-tablespaces'; then
    mysql_dump_options+=(--no-tablespaces)
  fi
  if [[ "$MYSQL_DUMP_INCLUDE_ROUTINES" == "1" ]]; then
    mysql_dump_options+=(--routines)
  fi
  if [[ "$MYSQL_DUMP_INCLUDE_EVENTS" == "1" ]]; then
    mysql_dump_options+=(--events)
  fi

  MYSQL_PWD="$DB_PASSWORD" mysqldump \
    "${mysql_client_args[@]}" \
    -u "$DB_USER" \
    "${mysql_dump_options[@]}" \
    "$DB_NAME" | gzip -9 > "$PLAIN"

  gzip -t "$PLAIN"
  [[ -s "$PLAIN" ]] || die "Database dump is empty"

  if [[ "$RESTORE_TEST" == "1" ]]; then
    RESTORE_DB="pt_backup_verify_$(date '+%Y%m%d%H%M%S')_$$"
    log "Running isolated restore validation in $RESTORE_DB"
    mysql_admin_exec "CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
      || die "Could not create restore-test database. Configure RESTORE_MYSQL_ADMIN_CNF or RESTORE_DB_ADMIN_*."
    gunzip -c "$PLAIN" | mysql_restore_into "$RESTORE_DB"
    restored_table_count="$(
      mysql_admin_exec "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$RESTORE_DB'" \
        | tail -n1 | tr -d '[:space:]'
    )"
    [[ "$restored_table_count" =~ ^[0-9]+$ && "$restored_table_count" -gt 0 ]] \
      || die "Restore test completed but no tables were found"

    for table in $CRITICAL_TABLES; do
      [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid critical table name: $table"
      exists="$(
        mysql_admin_exec "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$RESTORE_DB' AND table_name='$table'" \
          | tail -n1 | tr -d '[:space:]'
      )"
      [[ "$exists" == "1" ]] || die "Critical table missing after restore: $table"
      critical_tables_verified=$((critical_tables_verified + 1))
    done
    restore_status="passed"
    mysql_admin_exec "DROP DATABASE IF EXISTS \`$RESTORE_DB\`"
    RESTORE_DB=""
  fi
else
  PLAIN="$TMP_DIR/$BASE.dump"
  log "Creating consistent PostgreSQL backup of $DB_NAME"
  PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "${DB_HOST:-127.0.0.1}" \
    -p "${DB_PORT:-5432}" \
    -U "$DB_USER" \
    -Fc \
    -f "$PLAIN" \
    "$DB_NAME"
  [[ -s "$PLAIN" ]] || die "Database dump is empty"
  pg_restore --list "$PLAIN" >/dev/null

  if [[ "$RESTORE_TEST" == "1" ]]; then
    RESTORE_DB="pt_backup_verify_$(date '+%Y%m%d%H%M%S')_$$"
    log "Running isolated PostgreSQL restore validation in $RESTORE_DB"
    pg_admin_exec "CREATE DATABASE \"$RESTORE_DB\"" \
      || die "Could not create restore-test database. Configure RESTORE_DB_ADMIN_*."
    if [[ -n "${RESTORE_DB_ADMIN_USER:-}" ]]; then
      PGPASSWORD="${RESTORE_DB_ADMIN_PASSWORD:-}" pg_restore \
        -h "${RESTORE_DB_ADMIN_HOST:-${DB_HOST:-127.0.0.1}}" \
        -p "${RESTORE_DB_ADMIN_PORT:-${DB_PORT:-5432}}" \
        -U "$RESTORE_DB_ADMIN_USER" \
        -d "$RESTORE_DB" \
        --no-owner --no-privileges "$PLAIN"
      restored_table_count="$(
        PGPASSWORD="${RESTORE_DB_ADMIN_PASSWORD:-}" psql \
          -h "${RESTORE_DB_ADMIN_HOST:-${DB_HOST:-127.0.0.1}}" \
          -p "${RESTORE_DB_ADMIN_PORT:-${DB_PORT:-5432}}" \
          -U "$RESTORE_DB_ADMIN_USER" -d "$RESTORE_DB" -Atc \
          "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'"
      )"
    else
      sudo -u postgres -- pg_restore -d "$RESTORE_DB" --no-owner --no-privileges "$PLAIN"
      restored_table_count="$(sudo -u postgres -- psql -d "$RESTORE_DB" -Atc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")"
    fi
    [[ "$restored_table_count" =~ ^[0-9]+$ && "$restored_table_count" -gt 0 ]] \
      || die "Restore test completed but no tables were found"
    restore_status="passed"
    pg_admin_exec "DROP DATABASE IF EXISTS \"$RESTORE_DB\""
    RESTORE_DB=""
  fi
fi

PLAIN_SHA256="$(sha256sum "$PLAIN" | awk '{print $1}')"
ENCRYPTED="$DEST_DIR/$BASE$( [[ "$DB_DRIVER" =~ ^(mysql|mariadb)$ ]] && printf '.sql.gz.age' || printf '.dump.age' )"
log "Encrypting backup with age"
age -R "$AGE_RECIPIENT_FILE" -o "$ENCRYPTED.tmp" "$PLAIN"
mv "$ENCRYPTED.tmp" "$ENCRYPTED"
ENCRYPTED_SHA256="$(sha256sum "$ENCRYPTED" | awk '{print $1}')"
ENCRYPTED_SIZE="$(stat -c '%s' "$ENCRYPTED")"

python3 - "$MANIFEST" "$KIND" "$STAMP" "$DB_DRIVER" "$ENCRYPTED" "$ENCRYPTED_SHA256" "$PLAIN_SHA256" "$ENCRYPTED_SIZE" "$restore_status" "$restored_table_count" "$critical_tables_verified" <<'PY'
import json, os, sys
manifest, kind, stamp, driver, encrypted, encrypted_sha, plain_sha, size, restore_status, table_count, critical_verified = sys.argv[1:]
payload = {
    "schema": 1,
    "kind": kind,
    "created_at": stamp,
    "driver": driver,
    "file": os.path.basename(encrypted),
    "encrypted": True,
    "size_bytes": int(size),
    "sha256_encrypted": encrypted_sha,
    "sha256_plain": plain_sha,
    "restore_test": restore_status,
    "restore_verified_tables": int(table_count or 0),
    "critical_tables_verified": int(critical_verified or 0),
}
with open(manifest, "w", encoding="utf-8") as fh:
    json.dump(payload, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
PY
chmod 600 "$ENCRYPTED" "$MANIFEST"

if [[ "$KIND" == "daily" ]]; then
  if [[ "$WEEKDAY" == "7" ]]; then
    weekly_dir="$BACKUP_ROOT/weekly/$YEAR"
    mkdir -p "$weekly_dir"
    ln -f "$ENCRYPTED" "$weekly_dir/$(basename "$ENCRYPTED")" 2>/dev/null || cp -p "$ENCRYPTED" "$weekly_dir/"
    ln -f "$MANIFEST" "$weekly_dir/$(basename "$MANIFEST")" 2>/dev/null || cp -p "$MANIFEST" "$weekly_dir/"
  fi
  if [[ "$DAY_OF_MONTH" == "01" ]]; then
    monthly_dir="$BACKUP_ROOT/monthly/$YEAR"
    mkdir -p "$monthly_dir"
    ln -f "$ENCRYPTED" "$monthly_dir/$(basename "$ENCRYPTED")" 2>/dev/null || cp -p "$ENCRYPTED" "$monthly_dir/"
    ln -f "$MANIFEST" "$monthly_dir/$(basename "$MANIFEST")" 2>/dev/null || cp -p "$MANIFEST" "$monthly_dir/"
  fi
fi

if [[ -n "$OFFSITE_RCLONE_REMOTE" ]]; then
  need rclone
  remote_base="${OFFSITE_RCLONE_REMOTE%/}/$KIND/$YEAR/$MONTH"
  log "Uploading encrypted backup to configured rclone remote"
  rclone copyto "$ENCRYPTED" "$remote_base/$(basename "$ENCRYPTED")" --retries 5 --low-level-retries 10
  rclone copyto "$MANIFEST" "$remote_base/$(basename "$MANIFEST")" --retries 5 --low-level-retries 10
fi

find "$BACKUP_ROOT/daily" -type f -mtime "+$LOCAL_DAILY_DAYS" -delete 2>/dev/null || true
find "$BACKUP_ROOT/pre-deploy" -type f -mtime "+$LOCAL_PREDEPLOY_DAYS" -delete 2>/dev/null || true
find "$BACKUP_ROOT/manual" -type f -mtime "+$LOCAL_MANUAL_DAYS" -delete 2>/dev/null || true
find "$BACKUP_ROOT/weekly" -type f -mtime "+$LOCAL_WEEKLY_DAYS" -delete 2>/dev/null || true
find "$BACKUP_ROOT/monthly" -type f -mtime "+$LOCAL_MONTHLY_DAYS" -delete 2>/dev/null || true
find "$BACKUP_ROOT" -type d -empty -delete 2>/dev/null || true

rm -f "$PLAIN"
PLAIN=""

log "Backup completed and verified: $ENCRYPTED"
printf 'BACKUP_FILE=%s\n' "$ENCRYPTED"
printf 'MANIFEST_FILE=%s\n' "$MANIFEST"
