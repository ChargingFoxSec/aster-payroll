<?php

namespace App\Services\Solana;

readonly class AnchorInstructionResult
{
    public function __construct(
        public string $companyPubkey,
        public string $accountPubkey,
        public string $txSignature,
        public ?string $companyInitializationTxSignature = null,
        public ?string $authorityPubkey = null,
        public ?string $finalizedBy = null,
        public ?int $approvedAt = null,
        public ?int $executedAt = null,
    ) {}
}
