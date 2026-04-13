#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK_DIR="${ASTER_CONFIDENTIAL_POC_DIR:-${ROOT_DIR}/onchain/.artifacts/confidential-payroll-poc}"
LOG_DIR="${WORK_DIR}/logs"
OUTPUT_PATH="${ASTER_CONFIDENTIAL_POC_OUTPUT:-${WORK_DIR}/receipt.json}"
DEFAULT_RPC_URL="http://host.docker.internal:8899"
if getent hosts aster-payroll-confidential-validator >/dev/null 2>&1; then
    DEFAULT_RPC_URL="http://aster-payroll-confidential-validator:8899"
fi
RPC_URL="${ASTER_SOLANA_RPC_URL:-${RPC_URL:-${DEFAULT_RPC_URL}}}"
TOKEN_PROGRAM_ID="${ASTER_TOKEN_2022_PROGRAM_ID:-TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb}"
MINT_DECIMALS="${ASTER_CONFIDENTIAL_MINT_DECIMALS:-2}"
MINT_AMOUNT="${ASTER_CONFIDENTIAL_MINT_AMOUNT:-1000}"
TRANSFER_AMOUNT="${ASTER_CONFIDENTIAL_TRANSFER_AMOUNT:-250}"
PAYER_KEYPAIR="${WORK_DIR}/payer.json"
PAYER_CONFIG="${WORK_DIR}/payer-config.yml"
COMPANY_OWNER_KEYPAIR="${WORK_DIR}/company-owner.json"
COMPANY_OWNER_CONFIG="${WORK_DIR}/company-owner-config.yml"
EMPLOYEE_OWNER_KEYPAIR="${WORK_DIR}/employee-owner.json"
EMPLOYEE_OWNER_CONFIG="${WORK_DIR}/employee-owner-config.yml"

umask 077
mkdir -p "${LOG_DIR}"

require_binary() {
    local name="$1"

    if ! command -v "${name}" >/dev/null 2>&1; then
        echo "Missing required binary: ${name}" >&2
        exit 1
    fi
}

require_binary jq
require_binary solana
require_binary solana-keygen
require_binary spl-token

generate_keypair() {
    local path="$1"

    if [[ ! -f "${path}" ]]; then
        solana-keygen new --silent --no-bip39-passphrase --force -o "${path}" >/dev/null
    fi
}

write_config() {
    local config_path="$1"
    local keypair_path="$2"

    solana config set \
        --config "${config_path}" \
        --url "${RPC_URL}" \
        --keypair "${keypair_path}" \
        >/dev/null
}

run_step() {
    local name="$1"
    shift

    local log_path="${LOG_DIR}/${name}.json"
    "$@" --output json-compact >"${log_path}"
    cat "${log_path}"
}

json_value() {
    local path="$1"
    local query="$2"

    jq -er "${query}" "${path}" 2>/dev/null || true
}

extract_signature() {
    local path="$1"
    local signature

    signature="$(json_value "${path}" '.commandOutput.signature // .signature // .txid // .transactionSignature // empty')"
    if [[ -n "${signature}" ]]; then
        echo "${signature}"
        return
    fi

    grep -Eo '[1-9A-HJ-NP-Za-km-z]{80,90}' "${path}" | head -n 1 || true
}

extract_address() {
    local path="$1"
    local address

    address="$(json_value "${path}" '.commandOutput.address // .commandOutput.account // .commandOutput.walletAddress // .address // .account // .walletAddress // empty')"
    if [[ -n "${address}" ]]; then
        echo "${address}"
        return
    fi

    grep -Eo '[1-9A-HJ-NP-Za-km-z]{32,44}' "${path}" | head -n 1 || true
}

generate_keypair "${PAYER_KEYPAIR}"
generate_keypair "${COMPANY_OWNER_KEYPAIR}"
generate_keypair "${EMPLOYEE_OWNER_KEYPAIR}"

write_config "${PAYER_CONFIG}" "${PAYER_KEYPAIR}"
write_config "${COMPANY_OWNER_CONFIG}" "${COMPANY_OWNER_KEYPAIR}"
write_config "${EMPLOYEE_OWNER_CONFIG}" "${EMPLOYEE_OWNER_KEYPAIR}"

PAYER_PUBKEY="$(solana address -k "${PAYER_KEYPAIR}")"
COMPANY_OWNER_PUBKEY="$(solana address -k "${COMPANY_OWNER_KEYPAIR}")"
EMPLOYEE_OWNER_PUBKEY="$(solana address -k "${EMPLOYEE_OWNER_KEYPAIR}")"

solana --url "${RPC_URL}" airdrop 100 "${PAYER_PUBKEY}" >/dev/null
solana --url "${RPC_URL}" balance "${PAYER_PUBKEY}" >/dev/null

run_step create_mint \
    spl-token \
    --config "${PAYER_CONFIG}" \
    --program-2022 \
    create-token \
    --decimals "${MINT_DECIMALS}" \
    --enable-confidential-transfers auto \
    >/dev/null

MINT_ADDRESS="$(extract_address "${LOG_DIR}/create_mint.json")"
if [[ -z "${MINT_ADDRESS}" ]]; then
    echo "Failed to determine mint address." >&2
    cat "${LOG_DIR}/create_mint.json" >&2
    exit 1
fi

run_step create_company_account \
    spl-token \
    --config "${COMPANY_OWNER_CONFIG}" \
    --program-2022 \
    create-account \
    "${MINT_ADDRESS}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

COMPANY_TOKEN_ACCOUNT="$(spl-token \
    --config "${COMPANY_OWNER_CONFIG}" \
    --program-2022 \
    accounts \
    --output json-compact | jq -er --arg mint "${MINT_ADDRESS}" '.accounts[] | select(.mint == $mint and .isAssociated == true) | .address' | head -n 1)"
if [[ -z "${COMPANY_TOKEN_ACCOUNT}" ]]; then
    echo "Failed to determine company token account." >&2
    cat "${LOG_DIR}/create_company_account.json" >&2
    exit 1
fi

run_step create_employee_account \
    spl-token \
    --config "${PAYER_CONFIG}" \
    --program-2022 \
    create-account \
    "${MINT_ADDRESS}" \
    --owner "${EMPLOYEE_OWNER_PUBKEY}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

EMPLOYEE_TOKEN_ACCOUNT="$(spl-token \
    --config "${EMPLOYEE_OWNER_CONFIG}" \
    --program-2022 \
    accounts \
    --output json-compact | jq -er --arg mint "${MINT_ADDRESS}" '.accounts[] | select(.mint == $mint and .isAssociated == true) | .address' | head -n 1)"
if [[ -z "${EMPLOYEE_TOKEN_ACCOUNT}" ]]; then
    echo "Failed to determine employee token account." >&2
    cat "${LOG_DIR}/create_employee_account.json" >&2
    exit 1
fi

run_step configure_company_confidential_account \
    spl-token \
    --config "${COMPANY_OWNER_CONFIG}" \
    --program-2022 \
    configure-confidential-transfer-account \
    "${MINT_ADDRESS}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

run_step configure_employee_confidential_account \
    spl-token \
    --config "${EMPLOYEE_OWNER_CONFIG}" \
    --program-2022 \
    configure-confidential-transfer-account \
    "${MINT_ADDRESS}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

run_step mint_company_balance \
    spl-token \
    --config "${PAYER_CONFIG}" \
    --program-2022 \
    mint \
    "${MINT_ADDRESS}" \
    "${MINT_AMOUNT}" \
    "${COMPANY_TOKEN_ACCOUNT}" \
    >/dev/null

run_step deposit_company_confidential_balance \
    spl-token \
    --config "${COMPANY_OWNER_CONFIG}" \
    --program-2022 \
    deposit-confidential-tokens \
    "${MINT_ADDRESS}" \
    "${MINT_AMOUNT}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

run_step apply_company_pending_balance \
    spl-token \
    --config "${COMPANY_OWNER_CONFIG}" \
    --program-2022 \
    apply-pending-balance \
    "${MINT_ADDRESS}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

run_step confidential_transfer \
    spl-token \
    --config "${COMPANY_OWNER_CONFIG}" \
    --program-2022 \
    transfer \
    "${MINT_ADDRESS}" \
    "${TRANSFER_AMOUNT}" \
    "${EMPLOYEE_TOKEN_ACCOUNT}" \
    --confidential \
    --from "${COMPANY_TOKEN_ACCOUNT}" \
    --allow-non-system-account-recipient \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

run_step apply_employee_pending_balance \
    spl-token \
    --config "${EMPLOYEE_OWNER_CONFIG}" \
    --program-2022 \
    apply-pending-balance \
    "${MINT_ADDRESS}" \
    --fee-payer "${PAYER_KEYPAIR}" \
    >/dev/null

COMPANY_PUBLIC_BALANCE="$(spl-token --config "${COMPANY_OWNER_CONFIG}" --program-2022 balance "${MINT_ADDRESS}")"
EMPLOYEE_PUBLIC_BALANCE="$(spl-token --config "${EMPLOYEE_OWNER_CONFIG}" --program-2022 balance "${MINT_ADDRESS}")"

jq -n \
    --arg generated_at "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" \
    --arg rpc_url "${RPC_URL}" \
    --arg token_program_id "${TOKEN_PROGRAM_ID}" \
    --arg payer "${PAYER_PUBKEY}" \
    --arg company_owner "${COMPANY_OWNER_PUBKEY}" \
    --arg employee_owner "${EMPLOYEE_OWNER_PUBKEY}" \
    --arg mint "${MINT_ADDRESS}" \
    --arg company_token_account "${COMPANY_TOKEN_ACCOUNT}" \
    --arg employee_token_account "${EMPLOYEE_TOKEN_ACCOUNT}" \
    --arg company_public_balance "${COMPANY_PUBLIC_BALANCE}" \
    --arg employee_public_balance "${EMPLOYEE_PUBLIC_BALANCE}" \
    --argjson mint_decimals "${MINT_DECIMALS}" \
    --argjson mint_amount "${MINT_AMOUNT}" \
    --argjson transfer_amount "${TRANSFER_AMOUNT}" \
    --arg create_mint_signature "$(extract_signature "${LOG_DIR}/create_mint.json")" \
    --arg create_company_account_signature "$(extract_signature "${LOG_DIR}/create_company_account.json")" \
    --arg create_employee_account_signature "$(extract_signature "${LOG_DIR}/create_employee_account.json")" \
    --arg configure_company_signature "$(extract_signature "${LOG_DIR}/configure_company_confidential_account.json")" \
    --arg configure_employee_signature "$(extract_signature "${LOG_DIR}/configure_employee_confidential_account.json")" \
    --arg mint_company_balance_signature "$(extract_signature "${LOG_DIR}/mint_company_balance.json")" \
    --arg deposit_signature "$(extract_signature "${LOG_DIR}/deposit_company_confidential_balance.json")" \
    --arg apply_company_signature "$(extract_signature "${LOG_DIR}/apply_company_pending_balance.json")" \
    --arg confidential_transfer_signature "$(extract_signature "${LOG_DIR}/confidential_transfer.json")" \
    --arg apply_employee_signature "$(extract_signature "${LOG_DIR}/apply_employee_pending_balance.json")" \
    --arg work_dir "${WORK_DIR}" \
    --arg raw_logs_dir "${LOG_DIR}" \
    '{
        generated_at: $generated_at,
        network: {
            rpc_url: $rpc_url,
            token_program_id: $token_program_id
        },
        actors: {
            payer: $payer,
            company_owner: $company_owner,
            employee_owner: $employee_owner
        },
        token: {
            mint: $mint,
            decimals: $mint_decimals,
            company_token_account: $company_token_account,
            employee_token_account: $employee_token_account
        },
        payroll: {
            minted_amount: $mint_amount,
            confidential_transfer_amount: $transfer_amount
        },
        transactions: {
            create_mint: $create_mint_signature,
            create_company_account: $create_company_account_signature,
            create_employee_account: $create_employee_account_signature,
            configure_company_confidential_account: $configure_company_signature,
            configure_employee_confidential_account: $configure_employee_signature,
            mint_company_balance: $mint_company_balance_signature,
            deposit_company_confidential_balance: $deposit_signature,
            apply_company_pending_balance: $apply_company_signature,
            confidential_transfer: $confidential_transfer_signature,
            apply_employee_pending_balance: $apply_employee_signature
        },
        balances: {
            company_public_balance: $company_public_balance,
            employee_public_balance: $employee_public_balance
        },
        artifacts: {
            work_dir: $work_dir,
            raw_logs_dir: $raw_logs_dir
        }
    }' >"${OUTPUT_PATH}"

cat "${OUTPUT_PATH}"
