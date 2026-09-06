#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_DIR="${1:-/var/www/api.petertecnet.com.br}"
SOURCE_DIR="${2:-$APP_DIR/ops/backup}"
CONFIG_DIR="/etc/petertecnet-backup"
CONFIG_FILE="$CONFIG_DIR/backup.env"
RECIPIENT_FILE="$CONFIG_DIR/age-recipient.txt"
BACKUP_ROOT="/var/backups/petertecnet/database"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "This installer must run as root." >&2
  exit 1
fi

[[ -d "$SOURCE_DIR" ]] || {
  echo "Backup source directory not found: $SOURCE_DIR" >&2
  exit 1
}

install_packages=()
command -v age >/dev/null 2>&1 || install_packages+=(age)
command -v rclone >/dev/null 2>&1 || install_packages+=(rclone)
command -v gzip >/dev/null 2>&1 || install_packages+=(gzip)
command -v mysqldump >/dev/null 2>&1 || install_packages+=(default-mysql-client)
command -v pg_dump >/dev/null 2>&1 || install_packages+=(postgresql-client)

if [[ "${#install_packages[@]}" -gt 0 ]]; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y "${install_packages[@]}"
fi

install -d -m 700 "$CONFIG_DIR"
install -d -m 700 "$BACKUP_ROOT"

if [[ -n "${PETERTECNET_BACKUP_AGE_RECIPIENT:-}" ]]; then
  printf '%s\n' "$PETERTECNET_BACKUP_AGE_RECIPIENT" > "$RECIPIENT_FILE"
  chmod 600 "$RECIPIENT_FILE"
elif [[ ! -s "$RECIPIENT_FILE" ]]; then
  echo "PETERTECNET_BACKUP_AGE_RECIPIENT is required on first install." >&2
  exit 2
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
  cat > "$CONFIG_FILE" <<EOF
# Peter Tecnet database backup configuration.
# This file is root-only and MUST NOT be committed to Git.
APP_DIR=$APP_DIR
BACKUP_ROOT=$BACKUP_ROOT
AGE_RECIPIENT_FILE=$RECIPIENT_FILE

# Local retention.
LOCAL_DAILY_DAYS=14
LOCAL_PREDEPLOY_DAYS=14
LOCAL_MANUAL_DAYS=30
LOCAL_WEEKLY_DAYS=70
LOCAL_MONTHLY_DAYS=400

# Every successful backup is restored into an isolated temporary database.
RESTORE_TEST=1
CRITICAL_TABLES="users establishments"

# Optional second off-site copy through rclone.
# Example after configuring an rclone remote named "petertecnet-offsite":
# OFFSITE_RCLONE_REMOTE="petertecnet-offsite:petertecnet/database"
OFFSITE_RCLONE_REMOTE=""

# If the database is remote or local root socket auth is unavailable,
# configure dedicated restore-test admin credentials below.
# RESTORE_DB_ADMIN_HOST=127.0.0.1
# RESTORE_DB_ADMIN_PORT=3306
# RESTORE_DB_ADMIN_USER=backup_validator
# RESTORE_DB_ADMIN_PASSWORD=
#
# MySQL/MariaDB alternative:
# RESTORE_MYSQL_ADMIN_CNF=/etc/petertecnet-backup/mysql-restore.cnf
EOF
  chmod 600 "$CONFIG_FILE"
fi

install -m 700 "$SOURCE_DIR/petertecnet-db-backup.sh" /usr/local/sbin/petertecnet-db-backup
install -m 644 "$SOURCE_DIR/petertecnet-db-backup.service" /etc/systemd/system/petertecnet-db-backup.service
install -m 644 "$SOURCE_DIR/petertecnet-db-backup.timer" /etc/systemd/system/petertecnet-db-backup.timer

systemctl daemon-reload
systemctl enable --now petertecnet-db-backup.timer

echo "Backup subsystem installed."
systemctl --no-pager --full status petertecnet-db-backup.timer || true
