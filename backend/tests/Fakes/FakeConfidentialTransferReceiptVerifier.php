<?php

namespace Tests\Fakes;

use App\Models\PayoutExecution;
use App\Services\Solana\ConfidentialTransferReceiptVerifier;

class FakeConfidentialTransferReceiptVerifier implements ConfidentialTransferReceiptVerifier
{
    /** @var array<int, array{execution_id:int,tx_signature:string}> */
    public array $calls = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    public function verify(PayoutExecution $execution, array $receipt): void
    {
        $txSignature = trim((string) data_get($receipt, 'transactions.confidential_transfer', ''));
        $this->calls[] = [
            'execution_id' => $execution->id,
            'tx_signature' => $txSignature,
        ];

        if (isset($this->failures[$txSignature])) {
            throw $this->failures[$txSignature];
        }
    }

    public function failOnSignature(string $txSignature, \Throwable $throwable): void
    {
        $this->failures[$txSignature] = $throwable;
    }
}
