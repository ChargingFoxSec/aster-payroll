<?php

return [
    'demo_company' => [
        'name' => env('ASTER_DEMO_COMPANY_NAME', 'Aster Payroll Demo Co.'),
        'slug' => env('ASTER_DEMO_COMPANY_SLUG', 'aster-payroll-demo'),
        'wallet_address' => env('ASTER_DEMO_COMPANY_WALLET'),
    ],

    'currency' => [
        'code' => env('ASTER_PAYROLL_CURRENCY', 'USDC'),
        'minor_unit' => (int) env('ASTER_PAYROLL_CURRENCY_MINOR_UNIT', 2),
    ],

    'anchor' => [
        'rpc_url' => env('ASTER_SOLANA_RPC_URL', 'http://aster-payroll-confidential-validator:8899'),
        'program_id' => env('ASTER_ANCHOR_PROGRAM_ID', '4SZ4Fdt4pYurKjtdfEkHvRm9zZ2uTnHmdkGFrQxp1EhE'),
        'wallet_path' => env('ASTER_ANCHOR_WALLET', env('ANCHOR_WALLET', '')),
        'script' => env('ASTER_ANCHOR_SCRIPT', base_path('../onchain/scripts/anchor-attest.js')),
        'node_binary' => env('ASTER_NODE_BINARY', 'node'),
        'timeout_seconds' => (int) env('ASTER_ANCHOR_TIMEOUT_SECONDS', 120),
    ],

    'confidential' => [
        'rpc_url' => env('ASTER_SOLANA_RPC_URL', 'http://aster-payroll-confidential-validator:8899'),
        'token_program_id' => env('ASTER_TOKEN_2022_PROGRAM_ID', 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb'),
        'poc_script' => env('ASTER_CONFIDENTIAL_POC_SCRIPT', base_path('../onchain/scripts/confidential-payroll-signer.js')),
        'work_dir' => env('ASTER_CONFIDENTIAL_POC_WORK_DIR', storage_path('app/private/payroll/confidential-poc')),
        'prepared_payload_dir' => env('ASTER_CONFIDENTIAL_PREPARED_PAYLOAD_DIR', 'payroll/prepared-payouts'),
        'imported_receipt_dir' => env('ASTER_CONFIDENTIAL_IMPORTED_RECEIPT_DIR', 'payroll/imported-receipts'),
        'mint_decimals' => (int) env('ASTER_CONFIDENTIAL_MINT_DECIMALS', 2),
        'mint_amount' => (int) env('ASTER_CONFIDENTIAL_MINT_AMOUNT', 1000),
        'transfer_amount' => (int) env('ASTER_CONFIDENTIAL_TRANSFER_AMOUNT', 250),
        'timeout_seconds' => (int) env('ASTER_CONFIDENTIAL_TIMEOUT_SECONDS', 300),
        'rpc_timeout_seconds' => (int) env('ASTER_CONFIDENTIAL_RPC_TIMEOUT_SECONDS', 10),
    ],
];
