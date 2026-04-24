#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCKER_PROJECT_NAME="${ASTER_DOCKER_PROJECT_NAME:-frontiers-hackathon_devcontainer}"
IMAGE_NAME="${ASTER_CONFIDENTIAL_VALIDATOR_IMAGE:-aster-payroll-confidential-validator}"
CONTAINER_NAME="${ASTER_CONFIDENTIAL_VALIDATOR_CONTAINER:-aster-payroll-confidential-validator}"
HOST_RPC_PORT="${ASTER_CONFIDENTIAL_RPC_PORT:-8899}"
HOST_WS_PORT="${ASTER_CONFIDENTIAL_WS_PORT:-8900}"
CONTAINER_RPC_PROXY_PORT="${ASTER_CONFIDENTIAL_CONTAINER_RPC_PROXY_PORT:-18899}"
CONTAINER_WS_PROXY_PORT="${ASTER_CONFIDENTIAL_CONTAINER_WS_PROXY_PORT:-18900}"
DOCKER_NETWORK="${ASTER_CONFIDENTIAL_DOCKER_NETWORK:-${DOCKER_PROJECT_NAME}_default}"
NETWORK_ALIAS="${ASTER_CONFIDENTIAL_NETWORK_ALIAS:-aster-payroll-confidential-validator}"
TOKEN_2022_PROGRAM_ID="${ASTER_TOKEN_2022_PROGRAM_ID:-TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb}"
ASTER_PAYROLL_PROGRAM_ID="${ASTER_ANCHOR_PROGRAM_ID:-4SZ4Fdt4pYurKjtdfEkHvRm9zZ2uTnHmdkGFrQxp1EhE}"
AGAVE_VERSION="${ASTER_AGAVE_VERSION:-v3.1.12}"
READINESS_RETRIES="${ASTER_CONFIDENTIAL_READINESS_RETRIES:-180}"
READINESS_DELAY_SECONDS="${ASTER_CONFIDENTIAL_READINESS_DELAY_SECONDS:-2}"
TOKEN_2022_PROGRAM_SO="${ASTER_TOKEN_2022_PROGRAM_SO:-${ROOT_DIR}/onchain/.artifacts/token-2022-program/spl_token_2022.so}"
TOKEN_2022_PROGRAM_SO_IN_CONTAINER="/workspaces/frontiers-hackathon${TOKEN_2022_PROGRAM_SO#"${ROOT_DIR}"}"
ASTER_PAYROLL_PROGRAM_SO="${ASTER_ANCHOR_PROGRAM_SO:-${ROOT_DIR}/onchain/target/deploy/aster_payroll.so}"
ASTER_PAYROLL_PROGRAM_SO_IN_CONTAINER="/workspaces/frontiers-hackathon${ASTER_PAYROLL_PROGRAM_SO#"${ROOT_DIR}"}"
LEDGER_DIR_IN_CONTAINER="${ASTER_CONFIDENTIAL_LEDGER_DIR_IN_CONTAINER:-/tmp/aster-confidential-ledger}"

if [[ ! -f "${TOKEN_2022_PROGRAM_SO}" ]]; then
    "${ROOT_DIR}/scripts/build-token-2022-program.sh"
fi

if [[ ! -f "${TOKEN_2022_PROGRAM_SO}" ]]; then
    echo "Token-2022 program binary not found: ${TOKEN_2022_PROGRAM_SO}" >&2
    exit 1
fi

if [[ ! -f "${ASTER_PAYROLL_PROGRAM_SO}" ]]; then
    echo "Aster Payroll Anchor program binary not found: ${ASTER_PAYROLL_PROGRAM_SO}" >&2
    echo "Build it with: cd onchain && anchor build" >&2
    exit 1
fi

docker build \
    --platform linux/arm64 \
    --build-arg "AGAVE_VERSION=${AGAVE_VERSION}" \
    -f "${ROOT_DIR}/.devcontainer/confidential-validator.Dockerfile" \
    -t "${IMAGE_NAME}" \
    "${ROOT_DIR}"

docker rm -f "${CONTAINER_NAME}" >/dev/null 2>&1 || true

docker run \
    --detach \
    --platform linux/arm64 \
    --name "${CONTAINER_NAME}" \
    --network "${DOCKER_NETWORK}" \
    --network-alias "${NETWORK_ALIAS}" \
    --publish "${HOST_RPC_PORT}:${CONTAINER_RPC_PROXY_PORT}" \
    --publish "${HOST_WS_PORT}:${CONTAINER_WS_PROXY_PORT}" \
    --security-opt seccomp=unconfined \
    --ulimit memlock=-1:-1 \
    --volume "${ROOT_DIR}:/workspaces/frontiers-hackathon" \
    --workdir /workspaces/frontiers-hackathon/onchain \
    "${IMAGE_NAME}" \
    bash -lc 'set -euo pipefail
        AGAVE_INSTALL_DIR="${AGAVE_INSTALL_DIR:-/home/vscode/.local/share/agave-source-build}"
        export PATH="${AGAVE_INSTALL_DIR}/bin:${PATH}"
        RPC_PROXY_PORT='"${CONTAINER_RPC_PROXY_PORT}"'
        WS_PROXY_PORT='"${CONTAINER_WS_PROXY_PORT}"'
        VALIDATOR_BIN="${AGAVE_INSTALL_DIR}/bin/solana-test-validator"
        if [[ ! -x "${VALIDATOR_BIN}" ]]; then
            VALIDATOR_BIN="$(command -v solana-test-validator || command -v agave-test-validator || true)"
        fi
        if [[ -z "${VALIDATOR_BIN}" ]]; then
            echo "No local validator binary found in helper image." >&2
            exit 127
        fi
        rm -rf "'"${LEDGER_DIR_IN_CONTAINER}"'"
        mkdir -p "'"${LEDGER_DIR_IN_CONTAINER}"'"
        socat "TCP-LISTEN:${RPC_PROXY_PORT},fork,reuseaddr,bind=0.0.0.0" TCP:127.0.0.1:8899 &
        socat "TCP-LISTEN:${WS_PROXY_PORT},fork,reuseaddr,bind=0.0.0.0" TCP:127.0.0.1:8900 &
        exec "${VALIDATOR_BIN}" \
            --ledger "'"${LEDGER_DIR_IN_CONTAINER}"'" \
            --bpf-program '"${TOKEN_2022_PROGRAM_ID}"' '"${TOKEN_2022_PROGRAM_SO_IN_CONTAINER}"' \
            --bpf-program '"${ASTER_PAYROLL_PROGRAM_ID}"' '"${ASTER_PAYROLL_PROGRAM_SO_IN_CONTAINER}"''

for _ in $(seq 1 "${READINESS_RETRIES}"); do
    if docker exec "${CONTAINER_NAME}" bash -lc "curl --silent --fail --header 'Content-Type: application/json' --data '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"getVersion\"}' 'http://127.0.0.1:8899' | grep -q '\"solana-core\"'" >/dev/null 2>&1; then
        echo "Confidential validator is ready."
        echo "RPC: http://127.0.0.1:${HOST_RPC_PORT}"
        echo "WS:  ws://127.0.0.1:${HOST_WS_PORT}"
        echo "App container RPC: http://${NETWORK_ALIAS}:8899"
        exit 0
    fi

    sleep "${READINESS_DELAY_SECONDS}"
done

echo "Confidential validator failed to become ready." >&2
docker logs "${CONTAINER_NAME}" >&2 || true
exit 1
