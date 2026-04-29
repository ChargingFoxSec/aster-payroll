<?php

namespace App\Services\Solana;

use App\Models\PayoutExecution;

interface ConfidentialTransferReceiptVerifier
{
    /**
     * @param  array<string, mixed>  $receipt
     */
    public function verify(PayoutExecution $execution, array $receipt): void;
}
