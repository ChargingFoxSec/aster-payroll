<?php

namespace App\Services\Solana;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollBatch;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessPayrollAnchorClient implements PayrollAnchorClient
{
    public function createEmploymentContract(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        string $currentCompensationRef,
    ): AnchorInstructionResult {
        return $this->runInstruction('create-employment-contract', [
            'company' => $this->companyPayload($company),
            'employee' => [
                'wallet_address' => $employee->wallet_address,
            ],
            'contract' => [
                'file_hash' => $contract->file_hash,
                'version' => $contract->version,
                'effective_at' => $contract->effective_date?->startOfDay()->timestamp,
                'pay_cycle' => $employee->pay_cycle,
                'current_compensation_ref' => $currentCompensationRef,
            ],
        ], __('ui.messages.anchor_employment_contract_failed'));
    }

    public function amendCompensation(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        CompensationAmendment $amendment,
        string $amendmentHash,
    ): AnchorInstructionResult {
        return $this->runInstruction('amend-compensation', [
            'company' => $this->companyPayload($company),
            'employee' => [
                'wallet_address' => $employee->wallet_address,
            ],
            'contract' => [
                'anchor_contract_pubkey' => $contract->anchor_contract_pubkey,
            ],
            'amendment' => [
                'effective_at' => $amendment->effective_date?->startOfDay()->timestamp,
                'amendment_hash' => $amendmentHash,
            ],
        ], __('ui.messages.anchor_compensation_amendment_failed'));
    }

    public function commitPayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        Collection $entries,
        string $entriesRoot,
        int $entryCount,
    ): AnchorInstructionResult {
        return $this->runInstruction('commit-payroll-batch', [
            'company' => $this->companyPayload($company),
            'batch' => [
                'period_year' => $payrollBatch->period_year,
                'period_month' => $payrollBatch->period_month,
                'entry_count' => $entryCount,
                'entries_root' => $entriesRoot,
            ],
        ], __('ui.messages.anchor_commit_payroll_batch_failed'));
    }

    public function approvePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $approvalRoot,
    ): AnchorInstructionResult {
        return $this->runInstruction('approve-payroll-batch', [
            'company' => $this->companyPayload($company),
            'batch' => [
                'period_year' => $payrollBatch->period_year,
                'period_month' => $payrollBatch->period_month,
                'anchor_batch_pubkey' => $payrollBatch->anchor_batch_pubkey,
                'approval_root' => $approvalRoot,
            ],
        ], __('ui.messages.anchor_approve_payroll_batch_failed'));
    }

    public function finalizePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $settlementRoot,
    ): AnchorInstructionResult {
        return $this->runInstruction('finalize-payroll-batch', [
            'company' => $this->companyPayload($company),
            'batch' => [
                'period_year' => $payrollBatch->period_year,
                'period_month' => $payrollBatch->period_month,
                'anchor_batch_pubkey' => $payrollBatch->anchor_batch_pubkey,
                'settlement_root' => $settlementRoot,
            ],
        ], __('ui.messages.anchor_finalize_payroll_batch_failed'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runInstruction(string $instruction, array $payload, string $failureMessage): AnchorInstructionResult
    {
        $scriptPath = (string) config('payroll.anchor.script');
        $walletPath = (string) config('payroll.anchor.wallet_path');

        if ($scriptPath === '' || ! is_file($scriptPath)) {
            throw new UserFacingException(__('ui.messages.anchor_bridge_missing'));
        }

        if ($walletPath === '' || ! is_file($walletPath)) {
            throw new UserFacingException(__('ui.messages.anchor_wallet_missing'));
        }

        try {
            $process = new Process(
                [(string) config('payroll.anchor.node_binary', 'node'), $scriptPath, $instruction],
                dirname($scriptPath),
                [
                    'ASTER_SOLANA_RPC_URL' => (string) config('payroll.anchor.rpc_url'),
                    'ASTER_ANCHOR_WALLET' => $walletPath,
                    'ANCHOR_WALLET' => $walletPath,
                    'ASTER_ANCHOR_PROGRAM_ID' => (string) config('payroll.anchor.program_id'),
                ],
                json_encode($payload, JSON_THROW_ON_ERROR),
                (float) config('payroll.anchor.timeout_seconds', 120),
            );
            $process->run();
        } catch (Throwable $throwable) {
            throw new UserFacingException($failureMessage, previous: $throwable);
        }

        if (! $process->isSuccessful()) {
            report(new ProcessFailedException($process));

            throw new UserFacingException($failureMessage);
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            throw new UserFacingException($failureMessage);
        }

        $companyPubkey = trim((string) data_get($decoded, 'company_pubkey', ''));
        $accountPubkey = trim((string) data_get($decoded, 'account_pubkey', ''));
        $txSignature = trim((string) data_get($decoded, 'tx_signature', ''));
        $companyInitializationTxSignature = data_get($decoded, 'company_initialization_tx_signature');
        $authorityPubkey = data_get($decoded, 'authority_pubkey');
        $finalizedBy = data_get($decoded, 'finalized_by');
        $approvedAt = data_get($decoded, 'approved_at');
        $executedAt = data_get($decoded, 'executed_at');

        if ($companyPubkey === '' || $accountPubkey === '' || $txSignature === '') {
            throw new UserFacingException($failureMessage);
        }

        return new AnchorInstructionResult(
            companyPubkey: $companyPubkey,
            accountPubkey: $accountPubkey,
            txSignature: $txSignature,
            companyInitializationTxSignature: is_string($companyInitializationTxSignature) && $companyInitializationTxSignature !== ''
                ? $companyInitializationTxSignature
                : null,
            authorityPubkey: is_string($authorityPubkey) && $authorityPubkey !== ''
                ? $authorityPubkey
                : null,
            finalizedBy: is_string($finalizedBy) && $finalizedBy !== ''
                ? $finalizedBy
                : null,
            approvedAt: is_numeric($approvedAt) ? (int) $approvedAt : null,
            executedAt: is_numeric($executedAt) ? (int) $executedAt : null,
        );
    }

    /**
     * @return array{name:string,slug:string,wallet_address:?string}
     */
    private function companyPayload(Company $company): array
    {
        return [
            'name' => $company->name,
            'slug' => $company->slug,
            'wallet_address' => $company->wallet_address,
        ];
    }
}
