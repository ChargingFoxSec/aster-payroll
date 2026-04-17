#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEFAULT_WORK_DIR="${ROOT_DIR}/onchain/.artifacts/confidential-payroll-poc"
WORK_DIR="${ASTER_CONFIDENTIAL_POC_DIR:-${DEFAULT_WORK_DIR}}"
LOG_DIR="${WORK_DIR}/logs"
DEFAULT_RPC_URL="http://host.docker.internal:8899"

if getent hosts aster-payroll-confidential-validator >/dev/null 2>&1; then
    DEFAULT_RPC_URL="http://aster-payroll-confidential-validator:8899"
fi

RPC_URL="${ASTER_SOLANA_RPC_URL:-${RPC_URL:-${DEFAULT_RPC_URL}}}"
TOKEN_PROGRAM_ID="${ASTER_TOKEN_2022_PROGRAM_ID:-TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb}"
MANIFEST_PATH="${ASTER_PAYOUT_MANIFEST:-}"
COMPANY_OWNER_KEYPAIR="${ASTER_COMPANY_OWNER_KEYPAIR:-}"
EMPLOYEE_OWNER_KEYPAIR="${ASTER_EMPLOYEE_OWNER_KEYPAIR:-${WORK_DIR}/employee-owner.json}"
PAYER_KEYPAIR="${ASTER_PAYOUT_FEE_PAYER_KEYPAIR:-${WORK_DIR}/payer.json}"
PAYER_CONFIG="${WORK_DIR}/payer-config.yml"
COMPANY_OWNER_CONFIG="${WORK_DIR}/company-owner-config.yml"
EMPLOYEE_OWNER_CONFIG="${WORK_DIR}/employee-owner-config.yml"

usage() {
    cat <<'EOF'
Usage:
  ASTER_PAYOUT_MANIFEST=/abs/path/to/execution.json \
  ASTER_COMPANY_OWNER_KEYPAIR=/abs/path/to/admin-company-wallet.json \
  ./onchain/scripts/confidential-payroll-poc.sh

Optional environment variables:
  ASTER_SOLANA_RPC_URL
  ASTER_CONFIDENTIAL_POC_DIR
  ASTER_CONFIDENTIAL_POC_OUTPUT
  ASTER_EMPLOYEE_OWNER_KEYPAIR
  ASTER_PAYOUT_FEE_PAYER_KEYPAIR
  ASTER_CONFIDENTIAL_MINT_AMOUNT
EOF
}

while (($# > 0)); do
    case "$1" in
        --manifest)
            MANIFEST_PATH="$2"
            shift 2
            ;;
        --company-owner-keypair)
            COMPANY_OWNER_KEYPAIR="$2"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -z "${MANIFEST_PATH}" || -z "${COMPANY_OWNER_KEYPAIR}" ]]; then
    usage >&2
    exit 1
fi

if [[ ! -f "${MANIFEST_PATH}" ]]; then
    echo "Prepared payout manifest not found: ${MANIFEST_PATH}" >&2
    exit 1
fi

if [[ ! -f "${COMPANY_OWNER_KEYPAIR}" ]]; then
    echo "Admin-controlled company signer keypair not found: ${COMPANY_OWNER_KEYPAIR}" >&2
    exit 1
fi

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

MANIFEST_DIR="$(dirname "${MANIFEST_PATH}")"
OUTPUT_PATH="${ASTER_CONFIDENTIAL_POC_OUTPUT:-${MANIFEST_DIR}/receipt.json}"
MINT_DECIMALS="$(jq -er '.payroll.mint_decimals // 2' "${MANIFEST_PATH}")"
AMOUNT_MINOR="$(jq -er '.payroll.amount_minor' "${MANIFEST_PATH}")"
TRANSFER_AMOUNT="$(jq -er '.payroll.confidential_transfer_amount' "${MANIFEST_PATH}")"
EXECUTION_ID="$(jq -er '.execution.execution_id' "${MANIFEST_PATH}")"
PAYROLL_ENTRY_ID="$(jq -er '.execution.payroll_entry_id' "${MANIFEST_PATH}")"
PAYROLL_BATCH_ID="$(jq -er '.execution.payroll_batch_id' "${MANIFEST_PATH}")"
COMPANY_EXPECTED_WALLET="$(jq -r '.company.wallet_address // empty' "${MANIFEST_PATH}")"
COMPANY_NAME="$(jq -r '.company.name // "Aster Payroll Demo Co."' "${MANIFEST_PATH}")"
EMPLOYEE_NAME="$(jq -r '.employee.full_name // "Demo Employee"' "${MANIFEST_PATH}")"

MINT_AMOUNT="${ASTER_CONFIDENTIAL_MINT_AMOUNT:-$(jq -er '(.payroll.confidential_transfer_amount * 4) | floor' "${MANIFEST_PATH}")}"

umask 077
mkdir -p "${LOG_DIR}"

generate_keypair "${PAYER_KEYPAIR}"
generate_keypair "${EMPLOYEE_OWNER_KEYPAIR}"

write_config "${PAYER_CONFIG}" "${PAYER_KEYPAIR}"
write_config "${COMPANY_OWNER_CONFIG}" "${COMPANY_OWNER_KEYPAIR}"
write_config "${EMPLOYEE_OWNER_CONFIG}" "${EMPLOYEE_OWNER_KEYPAIR}"

PAYER_PUBKEY="$(solana address -k "${PAYER_KEYPAIR}")"
COMPANY_OWNER_PUBKEY="$(solana address -k "${COMPANY_OWNER_KEYPAIR}")"
EMPLOYEE_OWNER_PUBKEY="$(solana address -k "${EMPLOYEE_OWNER_KEYPAIR}")"

if [[ -n "${COMPANY_EXPECTED_WALLET}" && "${COMPANY_EXPECTED_WALLET}" != "${COMPANY_OWNER_PUBKEY}" ]]; then
    echo "Manifest expects company wallet ${COMPANY_EXPECTED_WALLET}, but provided signer resolves to ${COMPANY_OWNER_PUBKEY}." >&2
    exit 1
fi

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
APPROVED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

jq -n \
    --arg generated_at "${APPROVED_AT}" \
    --arg rpc_url "${RPC_URL}" \
    --arg token_program_id "${TOKEN_PROGRAM_ID}" \
    --arg manifest_path "${MANIFEST_PATH}" \
    --arg payer "${PAYER_PUBKEY}" \
    --arg company_owner "${COMPANY_OWNER_PUBKEY}" \
    --arg employee_owner "${EMPLOYEE_OWNER_PUBKEY}" \
    --arg company_name "${COMPANY_NAME}" \
    --arg employee_name "${EMPLOYEE_NAME}" \
    --arg mint "${MINT_ADDRESS}" \
    --arg company_token_account "${COMPANY_TOKEN_ACCOUNT}" \
    --arg employee_token_account "${EMPLOYEE_TOKEN_ACCOUNT}" \
    --arg company_public_balance "${COMPANY_PUBLIC_BALANCE}" \
    --arg employee_public_balance "${EMPLOYEE_PUBLIC_BALANCE}" \
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
    --argjson execution_id "${EXECUTION_ID}" \
    --argjson payroll_entry_id "${PAYROLL_ENTRY_ID}" \
    --argjson payroll_batch_id "${PAYROLL_BATCH_ID}" \
    --argjson mint_decimals "${MINT_DECIMALS}" \
    --argjson transfer_amount "${TRANSFER_AMOUNT}" \
    --argjson amount_minor "${AMOUNT_MINOR}" \
    '{
        generated_at: $generated_at,
        execution: {
            execution_id: $execution_id,
            payroll_entry_id: $payroll_entry_id,
            payroll_batch_id: $payroll_batch_id
        },
        network: {
            rpc_url: $rpc_url,
            token_program_id: $token_program_id
        },
        approval: {
            method: "local_signer",
            approving_wallet_address: $company_owner,
            approved_at: $generated_at,
            manifest_path: $manifest_path
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
            company_name: $company_name,
            employee_name: $employee_name,
            amount_minor: $amount_minor,
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
