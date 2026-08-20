#!/usr/bin/env bash
#
# Restore drill (16.3 reliability) — proves the backup chain end to end:
# take a fresh backup, restore it into a scratch database, and count rows
# in the load-bearing tables. Run monthly; record the timings in the DR
# runbook (docs/runbooks/disaster-recovery.md).
#
# Usage: scripts/restore-drill.sh [scratch_db_name]
set -euo pipefail

SCRATCH_DB="${1:-atheris_restore_drill}"
START=$(date +%s)

echo "==> 1/4 Taking a fresh residency-guarded backup"
php artisan atheris:backup --keep=30

LATEST=$(ls -t storage/app/private/backups/*.sql | head -1)
echo "    Backup: ${LATEST}"

echo "==> 2/4 Restoring into scratch database '${SCRATCH_DB}'"
DB_USER="${DB_USERNAME:-root}"
DB_PASS="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"

MYSQL_PWD="${DB_PASS}" mysql -h "${DB_HOST}" -u "${DB_USER}" \
  -e "DROP DATABASE IF EXISTS ${SCRATCH_DB}; CREATE DATABASE ${SCRATCH_DB} CHARACTER SET utf8mb4;"
MYSQL_PWD="${DB_PASS}" mysql -h "${DB_HOST}" -u "${DB_USER}" "${SCRATCH_DB}" < "${LATEST}"

echo "==> 3/4 Verifying restored row counts"
for TABLE in tenants users controls control_exceptions audit_trails; do
  COUNT=$(MYSQL_PWD="${DB_PASS}" mysql -N -h "${DB_HOST}" -u "${DB_USER}" "${SCRATCH_DB}" \
    -e "SELECT COUNT(*) FROM ${TABLE};")
  echo "    ${TABLE}: ${COUNT} rows"
  if [ "${TABLE}" = "audit_trails" ] && [ "${COUNT}" -eq 0 ]; then
    echo "!! audit_trails restored empty — investigate before signing the drill off." >&2
    exit 1
  fi
done

echo "==> 4/4 Dropping scratch database"
MYSQL_PWD="${DB_PASS}" mysql -h "${DB_HOST}" -u "${DB_USER}" -e "DROP DATABASE ${SCRATCH_DB};"

END=$(date +%s)
echo "Restore drill complete in $((END - START))s — record this as the measured RTO input."
