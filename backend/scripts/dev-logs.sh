#!/usr/bin/env bash

set -euo pipefail

if php -r 'exit(function_exists("pcntl_fork") ? 0 : 1);'; then
    exec php artisan pail --timeout=0
fi

echo "pcntl extension missing; skipping Laravel Pail."
echo "Rebuild the devcontainer after updating .devcontainer/Dockerfile if you want live Pail logs."

exec tail -f /dev/null
