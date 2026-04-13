#!/usr/bin/env bash
set -euo pipefail

if ! yarn --version >/dev/null 2>&1; then
  corepack enable
  corepack prepare yarn@stable --activate
fi

if ! anchor --version >/dev/null 2>&1; then
  bash -lc 'source "$HOME/.cargo/env" && cargo install --git https://github.com/solana-foundation/anchor --tag v0.32.1 anchor-cli --locked --force'
fi

echo "Toolchain versions:"
XDEBUG_MODE=off php --version | head -n 1
composer --version
node --version
yarn --version
rustc --version
cargo --version
solana --version
anchor --version

solana config set --url devnet >/dev/null

if [ ! -f "$HOME/.config/solana/id.json" ]; then
  solana-keygen new --no-bip39-passphrase -o "$HOME/.config/solana/id.json" --silent
fi

echo ""
echo "MySQL: mysql -h mysql -uaster -paster aster_payroll"
echo "Redis: redis-cli -h redis"
