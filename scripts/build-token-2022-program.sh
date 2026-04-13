#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_CONTAINER="${ASTER_APP_CONTAINER:-frontiers-hackathon_devcontainer-app-1}"
TOKEN_2022_REPO="${ASTER_TOKEN_2022_REPO:-https://github.com/solana-program/token-2022}"
TOKEN_2022_TAG="${ASTER_TOKEN_2022_TAG:-program@v10.0.0}"
ARTIFACT_DIR="${ROOT_DIR}/onchain/.artifacts/token-2022-program"
SOURCE_DIR_IN_CONTAINER="/workspaces/frontiers-hackathon/onchain/.artifacts/token-2022-program/source"
OUTPUT_SO_IN_CONTAINER="/workspaces/frontiers-hackathon/onchain/.artifacts/token-2022-program/spl_token_2022.so"
OUTPUT_SO_ON_HOST="${ARTIFACT_DIR}/spl_token_2022.so"

mkdir -p "${ARTIFACT_DIR}"

docker exec \
    -e ASTER_TOKEN_2022_REPO="${TOKEN_2022_REPO}" \
    -e ASTER_TOKEN_2022_TAG="${TOKEN_2022_TAG}" \
    -e ASTER_TOKEN_2022_SOURCE_DIR="${SOURCE_DIR_IN_CONTAINER}" \
    -e ASTER_TOKEN_2022_OUTPUT_SO="${OUTPUT_SO_IN_CONTAINER}" \
    "${APP_CONTAINER}" \
    bash -lc 'set -euo pipefail
        rm -rf "${ASTER_TOKEN_2022_SOURCE_DIR}"
        git clone --depth 1 --branch "${ASTER_TOKEN_2022_TAG}" "${ASTER_TOKEN_2022_REPO}" "${ASTER_TOKEN_2022_SOURCE_DIR}" >/dev/null
        cd "${ASTER_TOKEN_2022_SOURCE_DIR}"
        cargo build-sbf --manifest-path program/Cargo.toml >/tmp/aster-token-2022-build.log 2>&1
        cat /tmp/aster-token-2022-build.log
        cp -f target/deploy/spl_token_2022.so "${ASTER_TOKEN_2022_OUTPUT_SO}"'

if [[ ! -f "${OUTPUT_SO_ON_HOST}" ]]; then
    echo "Failed to build Token-2022 SBF program." >&2
    exit 1
fi

echo "Built Token-2022 program:"
echo "${OUTPUT_SO_ON_HOST}"
