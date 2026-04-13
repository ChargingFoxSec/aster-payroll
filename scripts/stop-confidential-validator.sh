#!/usr/bin/env bash

set -euo pipefail

CONTAINER_NAME="${ASTER_CONFIDENTIAL_VALIDATOR_CONTAINER:-aster-payroll-confidential-validator}"

docker rm -f "${CONTAINER_NAME}" >/dev/null 2>&1 || true
