#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ASTER_DEMO_COMPOSE_FILE:-${ROOT_DIR}/.devcontainer/docker-compose.yml}"
APP_CONTAINER="${ASTER_DEMO_APP_CONTAINER:-frontiers-hackathon_devcontainer-app-1}"
VALIDATOR_CONTAINER="${ASTER_CONFIDENTIAL_VALIDATOR_CONTAINER:-aster-payroll-confidential-validator}"
BACKEND_DIR="/workspaces/frontiers-hackathon/backend"
LOG_PATH="${ASTER_DEMO_SERVER_LOG:-storage/logs/demo-env.log}"
PID_PATH="${ASTER_DEMO_SERVER_PID:-storage/logs/demo-env.pid}"

container_exists() {
    local name="$1"

    docker ps -a --format '{{.Names}}' | grep -Fxq "${name}"
}

container_running() {
    local name="$1"

    docker ps --format '{{.Names}}' | grep -Fxq "${name}"
}

echo "Starting Aster Payroll app and MySQL containers..."
docker compose -f "${COMPOSE_FILE}" up -d

if container_exists "${VALIDATOR_CONTAINER}"; then
    if container_running "${VALIDATOR_CONTAINER}"; then
        echo "Confidential validator is already running: ${VALIDATOR_CONTAINER}"
    else
        echo "Starting existing confidential validator container: ${VALIDATOR_CONTAINER}"
        docker start "${VALIDATOR_CONTAINER}" >/dev/null
    fi
else
    echo "Confidential validator container does not exist; creating it with scripts/start-confidential-validator.sh"
    "${ROOT_DIR}/scripts/start-confidential-validator.sh"
fi

echo "Ensuring Anchor wallet exists inside ${APP_CONTAINER}..."
docker exec "${APP_CONTAINER}" bash -lc '
set -euo pipefail

wallet_path="${ASTER_ANCHOR_WALLET:-${ANCHOR_WALLET:-${HOME}/.config/solana/id.json}}"
mkdir -p "$(dirname "${wallet_path}")"

if [[ ! -f "${wallet_path}" ]]; then
    solana-keygen new --no-bip39-passphrase -o "${wallet_path}" --silent
    echo "Created Anchor wallet: ${wallet_path}"
else
    echo "Anchor wallet already exists: ${wallet_path}"
fi
'

echo "Starting Laravel and Vite dev servers inside ${APP_CONTAINER}..."
docker exec -w "${BACKEND_DIR}" "${APP_CONTAINER}" bash -lc '
set -euo pipefail

log_path="'"${LOG_PATH}"'"
pid_path="'"${PID_PATH}"'"
mkdir -p "$(dirname "${log_path}")"
mkdir -p "$(dirname "${pid_path}")"

server_running=false

if [[ -f "${pid_path}" ]]; then
    existing_pid="$(cat "${pid_path}" 2>/dev/null || true)"

    if [[ "${existing_pid}" =~ ^[0-9]+$ ]] && kill -0 "${existing_pid}" >/dev/null 2>&1; then
        echo "Laravel/Vite dev servers are already running with PID ${existing_pid}."
        server_running=true
    else
        rm -f "${pid_path}"
    fi
fi

if [[ "${server_running}" != true ]]; then
    nohup bash -lc "exec composer dev" >"${log_path}" 2>&1 < /dev/null &
    echo "$!" >"${pid_path}"
    echo "Started composer dev with PID $(cat "${pid_path}"). Logs: ${log_path}"
fi
'

cat <<EOF

Aster Payroll demo environment is starting.

App URL:
  http://localhost:8000

Vite URL:
  http://localhost:5173

Validator RPC:
  http://127.0.0.1:8899

Useful checks:
  docker exec -w ${BACKEND_DIR} ${APP_CONTAINER} php artisan payroll:demo-health
  docker exec -w ${BACKEND_DIR} ${APP_CONTAINER} tail -f ${LOG_PATH}
EOF
