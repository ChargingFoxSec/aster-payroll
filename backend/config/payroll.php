<?php

return [
    'demo_company' => [
        'name' => env('ASTER_DEMO_COMPANY_NAME', 'Aster Payroll Demo Co.'),
        'slug' => env('ASTER_DEMO_COMPANY_SLUG', 'aster-payroll-demo'),
        'wallet_address' => env('ASTER_DEMO_COMPANY_WALLET'),
    ],

    'confidential' => [
        'rpc_url' => env('ASTER_SOLANA_RPC_URL', 'http://aster-payroll-confidential-validator:8899'),
        'poc_script' => env('ASTER_CONFIDENTIAL_POC_SCRIPT', base_path('../onchain/scripts/confidential-payroll-poc.sh')),
        'work_dir' => env('ASTER_CONFIDENTIAL_POC_WORK_DIR', storage_path('app/private/payroll/confidential-poc')),
        'receipt_path' => env('ASTER_CONFIDENTIAL_RECEIPT_PATH', storage_path('app/private/payroll/confidential-payroll-receipt.json')),
        'mint_amount' => (int) env('ASTER_CONFIDENTIAL_MINT_AMOUNT', 1000),
        'transfer_amount' => (int) env('ASTER_CONFIDENTIAL_TRANSFER_AMOUNT', 250),
        'timeout_seconds' => (int) env('ASTER_CONFIDENTIAL_TIMEOUT_SECONDS', 300),
    ],
];
