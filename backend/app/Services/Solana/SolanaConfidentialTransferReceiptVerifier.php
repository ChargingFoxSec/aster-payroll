<?php

namespace App\Services\Solana;

use App\Exceptions\UserFacingException;
use App\Models\PayoutExecution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class SolanaConfidentialTransferReceiptVerifier implements ConfidentialTransferReceiptVerifier
{
    /**
     * @param  array<string, mixed>  $receipt
     */
    public function verify(PayoutExecution $execution, array $receipt): void
    {
        $signature = trim((string) data_get($receipt, 'transactions.confidential_transfer', ''));
        $rpcUrl = (string) config('payroll.confidential.rpc_url');

        if ($signature === '' || $rpcUrl === '') {
            throw new UserFacingException(__('ui.messages.receipt_chain_verification_failed'));
        }

        $this->assertReceiptUsesConfiguredTokenProgram($receipt);

        try {
            $response = Http::timeout((int) config('payroll.confidential.rpc_timeout_seconds', 10))
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 'aster-payroll-receipt',
                    'method' => 'getTransaction',
                    'params' => [
                        $signature,
                        [
                            'commitment' => 'confirmed',
                            'encoding' => 'jsonParsed',
                            'maxSupportedTransactionVersion' => 0,
                        ],
                    ],
                ]);
        } catch (Throwable $throwable) {
            throw new UserFacingException(__('ui.messages.receipt_chain_verification_failed'), previous: $throwable);
        }

        if (! $response->ok()) {
            throw new UserFacingException(__('ui.messages.receipt_chain_verification_failed'));
        }

        $body = $response->json();

        if (! is_array($body) || data_get($body, 'error') !== null) {
            throw new UserFacingException(__('ui.messages.receipt_chain_verification_failed'));
        }

        $transaction = data_get($body, 'result');

        if (! is_array($transaction)) {
            throw new UserFacingException(__('ui.messages.receipt_chain_transaction_missing'));
        }

        if (data_get($transaction, 'meta.err') !== null) {
            throw new UserFacingException(__('ui.messages.receipt_chain_transaction_failed'));
        }

        if (! $this->referencesExpectedSettlementPath($transaction, $receipt)) {
            throw new UserFacingException(__('ui.messages.receipt_chain_transaction_mismatch'));
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function assertReceiptUsesConfiguredTokenProgram(array $receipt): void
    {
        $receiptTokenProgramId = trim((string) data_get($receipt, 'network.token_program_id', ''));
        $configuredTokenProgramId = $this->tokenProgramId();

        if ($receiptTokenProgramId !== '' && $receiptTokenProgramId !== $configuredTokenProgramId) {
            throw new UserFacingException(__('ui.messages.receipt_chain_transaction_mismatch'));
        }
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $receipt
     */
    private function referencesExpectedSettlementPath(array $transaction, array $receipt): bool
    {
        $accountKeys = $this->accountKeys($transaction);
        $signerAccountKeys = $this->signerAccountKeys($transaction);
        $instructionProgramIds = $this->instructionProgramIds($transaction);
        $tokenProgramId = $this->tokenProgramId();

        if (! $accountKeys->contains($tokenProgramId) && ! $instructionProgramIds->contains($tokenProgramId)) {
            return false;
        }

        foreach (['token.mint', 'token.company_token_account', 'token.employee_token_account'] as $path) {
            $account = trim((string) data_get($receipt, $path, ''));

            if ($account !== '' && ! $accountKeys->contains($account)) {
                return false;
            }
        }

        $expectedSigner = trim((string) (
            data_get($receipt, 'approval.approving_wallet_address')
            ?? data_get($receipt, 'actors.company_owner')
            ?? ''
        ));

        if (
            $expectedSigner !== ''
            && ! ($signerAccountKeys->isNotEmpty() ? $signerAccountKeys : $accountKeys)->contains($expectedSigner)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return Collection<int, string>
     */
    private function accountKeys(array $transaction): Collection
    {
        return collect(data_get($transaction, 'transaction.message.accountKeys', []))
            ->map(fn (mixed $key): string => is_array($key) ? trim((string) data_get($key, 'pubkey', '')) : trim((string) $key))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return Collection<int, string>
     */
    private function signerAccountKeys(array $transaction): Collection
    {
        return collect(data_get($transaction, 'transaction.message.accountKeys', []))
            ->filter(fn (mixed $key): bool => is_array($key) && (bool) data_get($key, 'signer', false))
            ->map(fn (mixed $key): string => trim((string) data_get($key, 'pubkey', '')))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return Collection<int, string>
     */
    private function instructionProgramIds(array $transaction): Collection
    {
        $outerInstructions = collect(data_get($transaction, 'transaction.message.instructions', []));
        $innerInstructions = collect(data_get($transaction, 'meta.innerInstructions', []))
            ->flatMap(fn (mixed $group): array => is_array($group) ? (array) data_get($group, 'instructions', []) : []);

        return $outerInstructions
            ->merge($innerInstructions)
            ->map(fn (mixed $instruction): string => is_array($instruction) ? trim((string) data_get($instruction, 'programId', '')) : '')
            ->filter()
            ->values();
    }

    private function tokenProgramId(): string
    {
        return (string) config('payroll.confidential.token_program_id', 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb');
    }
}
