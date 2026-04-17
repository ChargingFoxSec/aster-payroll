#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

host="${ASTER_DEV_SERVER_HOST:-0.0.0.0}"
port="${ASTER_DEV_SERVER_PORT:-8000}"

exec php -S "${host}:${port}" -t public scripts/dev-server-router.php
