<?php

namespace App\Services\Solana;

readonly class AnchorInstructionResult
{
    public function __construct(
        public string $companyPubkey,
        public string $accountPubkey,
        public string $txSignature,
        public ?string $companyInitializationTxSignature = null,
    ) {}
}
