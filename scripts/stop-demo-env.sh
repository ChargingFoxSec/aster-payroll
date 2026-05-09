#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ASTER_DEMO_COMPOSE_FILE:-${ROOT_DIR}/.devcontainer/docker-compose.yml}"
APP_CONTAINER="${ASTER_DEMO_APP_CONTAINER:-frontiers-hackathon_devcontainer-app-1}"
VALIDATOR_CONTAINER="${ASTER_CONFIDENTIAL_VALIDATOR_CONTAINER:-aster-payroll-confidential-validator}"
BACKEND_DIR="/workspaces/frontiers-hackathon/backend"
PID_PATH="${ASTER_DEMO_SERVER_PID:-storage/logs/demo-env.pid}"

container_exists() {
    local name="$1"

    docker ps -a --format '{{.Names}}' | grep -Fxq "${name}"
}

container_running() {
    local name="$1"

    docker ps --format '{{.Names}}' | grep -Fxq "${name}"
}

if container_running "${APP_CONTAINER}"; then
    echo "Stopping Laravel and Vite dev server processes inside ${APP_CONTAINER}..."
    docker exec -w "${BACKEND_DIR}" "${APP_CONTAINER}" bash -lc '
set -euo pipefail

pid_path="'"${PID_PATH}"'"

if [[ -f "${pid_path}" ]]; then
    existing_pid="$(cat "${pid_path}" 2>/dev/null || true)"
    if [[ "${existing_pid}" =~ ^[0-9]+$ ]]; then
        kill "${existing_pid}" >/dev/null 2>&1 || true
    fi
    rm -f "${pid_path}"
fi

kill_matching_processes() {
    local pattern="$1"
    local self_pid="$$"
    local pids

    pids="$(ps -eo pid=,command= | awk -v pattern="${pattern}" -v self="${self_pid}" '"'"'$1 != self && $0 ~ pattern { print $1 }'"'"')"
    if [[ -n "${pids}" ]]; then
        echo "${pids}" | xargs kill >/dev/null 2>&1 || true
    fi
}

kill_matching_processes "concurrently.*--names=server,logs,vite"
kill_matching_processes "php -S .*scripts/dev-server-router.php"
kill_matching_processes "php artisan pail"
kill_matching_processes "node .*vite"
'
else
    echo "App container is not running; skipping dev server process stop."
fi

if container_exists "${VALIDATOR_CONTAINER}"; then
    if container_running "${VALIDATOR_CONTAINER}"; then
        echo "Stopping confidential validator container: ${VALIDATOR_CONTAINER}"
        docker stop "${VALIDATOR_CONTAINER}" >/dev/null
    else
        echo "Confidential validator is already stopped: ${VALIDATOR_CONTAINER}"
    fi
fi

echo "Stopping app and MySQL containers..."
docker compose -f "${COMPOSE_FILE}" stop

cat <<EOF

Aster Payroll demo environment stopped.

Note:
  The confidential validator container is stopped, not removed.
  Start it again with ./scripts/start-demo-env.sh to preserve the existing container state.
EOF
