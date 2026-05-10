#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_CONTAINER="${ASTER_DEMO_APP_CONTAINER:-frontiers-hackathon_devcontainer-app-1}"
MYSQL_CONTAINER="${ASTER_DEMO_MYSQL_CONTAINER:-frontiers-hackathon_devcontainer-mysql-1}"
BACKEND_DIR="/workspaces/frontiers-hackathon/backend"
DB_NAME="${ASTER_DEMO_DB_NAME:-aster_payroll}"
DB_USER="${ASTER_DEMO_DB_USER:-aster}"
DB_PASSWORD="${ASTER_DEMO_DB_PASSWORD:-aster}"

YES=false
RESET_CHAIN=false
SKIP_BACKUP=false

usage() {
    cat <<'EOF'
Usage:
  ./scripts/reset-demo-state.sh [--yes] [--reset-chain] [--skip-backup]

Resets the local Aster Payroll demo back to the seeded baseline:
  - starts the Docker demo environment
  - backs up MySQL and payroll storage by default
  - runs Laravel migrate:fresh --seed
  - keeps the local validator ledger by default
  - optionally recreates the local confidential validator with --reset-chain

Options:
  --yes           Run without an interactive confirmation prompt.
  --reset-chain   Recreate the local confidential validator before health check.
                  Use this when reusing the same payroll periods and company wallet.
  --skip-backup   Do not write the SQL/storage backup first.
  -h, --help      Show this help.
EOF
}

for arg in "$@"; do
    case "${arg}" in
        --yes)
            YES=true
            ;;
        --reset-chain)
            RESET_CHAIN=true
            ;;
        --skip-backup)
            SKIP_BACKUP=true
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: ${arg}" >&2
            usage >&2
            exit 64
            ;;
    esac
done

confirm_reset() {
    if [[ "${YES}" == true ]]; then
        return
    fi

    cat <<EOF
This will reset the Laravel/MySQL demo database for ${DB_NAME}.

Default behavior:
  - MySQL and payroll storage are backed up first.
  - The local Solana validator is preserved.

Extra selected behavior:
  - Reset local validator: ${RESET_CHAIN}
  - Skip backup: ${SKIP_BACKUP}

Type RESET to continue:
EOF

    read -r answer
    if [[ "${answer}" != "RESET" ]]; then
        echo "Aborted."
        exit 1
    fi
}

wait_for_mysql() {
    echo "Waiting for MySQL container readiness..."

    for _ in $(seq 1 60); do
        if docker exec -e MYSQL_PWD="${DB_PASSWORD}" "${MYSQL_CONTAINER}" \
            mysqladmin ping -h 127.0.0.1 "-u${DB_USER}" --silent >/dev/null 2>&1; then
            return
        fi

        sleep 2
    done

    echo "MySQL did not become ready in time." >&2
    exit 1
}

backup_demo_state() {
    if [[ "${SKIP_BACKUP}" == true ]]; then
        echo "Skipping backup by request."
        return
    fi

    local timestamp
    local backup_dir
    local payroll_storage_dir

    timestamp="$(date +%Y%m%d-%H%M%S)"
    backup_dir="${ASTER_DEMO_BACKUP_DIR:-${ROOT_DIR}/backups/demo-reset-${timestamp}}"
    payroll_storage_dir="${ROOT_DIR}/backend/storage/app/private/payroll"

    mkdir -p "${backup_dir}"

    echo "Backing up MySQL database to ${backup_dir}/aster_payroll.sql..."
    docker exec -e MYSQL_PWD="${DB_PASSWORD}" "${MYSQL_CONTAINER}" \
        mysqldump "-u${DB_USER}" "${DB_NAME}" > "${backup_dir}/aster_payroll.sql"

    if [[ -d "${payroll_storage_dir}" ]]; then
        echo "Archiving payroll storage to ${backup_dir}/payroll-storage.tgz..."
        tar -czf "${backup_dir}/payroll-storage.tgz" \
            -C "${ROOT_DIR}/backend/storage/app/private" \
            payroll
    else
        echo "No payroll storage directory found to archive."
    fi

    echo "Backup complete: ${backup_dir}"
}

confirm_reset

echo "Starting Aster Payroll demo environment..."
"${ROOT_DIR}/scripts/start-demo-env.sh"

wait_for_mysql
backup_demo_state

if [[ "${RESET_CHAIN}" == true ]]; then
    echo "Recreating local confidential validator..."
    "${ROOT_DIR}/scripts/start-confidential-validator.sh"
else
    cat <<'EOF'
Keeping the local validator state.
If you reset the database and then reuse an already committed period with the same company wallet,
the onchain batch PDA can collide. Use --reset-chain for an exact same-period rerun, or choose a new period.
EOF
fi

echo "Resetting Laravel database to seeded baseline..."
docker exec -w "${BACKEND_DIR}" "${APP_CONTAINER}" php artisan migrate:fresh --seed --force

echo "Clearing Laravel optimized caches..."
docker exec -w "${BACKEND_DIR}" "${APP_CONTAINER}" php artisan optimize:clear

echo "Running demo health check..."
docker exec -w "${BACKEND_DIR}" "${APP_CONTAINER}" php artisan payroll:demo-health

cat <<'EOF'

Demo state reset complete.

App:
  http://localhost:8000

Seeded accounts:
  admin@aster.test / password
  alice.payroll.demo@aster.test / password

Notes:
  - Browser cookies and Laravel file sessions are not deleted by this script.
  - Old payroll storage files are archived but not removed; fresh DB rows do not reference them.
EOF
