#!/usr/bin/env bash
# Export the local database's DATA (no schema) for import into production with phpMyAdmin.
# Production's schema comes from `artisan migrate` (first deploy), which avoids MySQL 8 → MariaDB
# DDL incompatibilities. Rows are written as REPLACE so settings seeded by the migrations are
# overwritten with your local values. Framework tables (migrations, sessions, cache, jobs) are skipped.
#
# Output contains personal data: it goes to storage/app/private/ (gitignored, never deployed).
set -euo pipefail

cd "$(dirname "$0")/.."
DB="${DB_DATABASE:-billing}"
OUT_DIR="storage/app/private/db-export"
OUT="$OUT_DIR/billing-data-$(date +%Y%m%d-%H%M).sql"
mkdir -p "$OUT_DIR"

SKIP=(migrations sessions cache cache_locks jobs job_batches failed_jobs password_reset_tokens)
IGNORE=()
for t in "${SKIP[@]}"; do IGNORE+=("--ignore-table=$DB.$t"); done

mysqldump -h "${DB_HOST:-127.0.0.1}" -u "${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
  --no-create-info --complete-insert --replace --skip-triggers --single-transaction \
  --set-gtid-purged=OFF --skip-comments \
  "${IGNORE[@]}" "$DB" > "$OUT"

echo "Wrote $OUT"
