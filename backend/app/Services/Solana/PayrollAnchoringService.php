<?php

namespace App\Services\Solana;

use App\Exceptions\UserFacingException;
use App\Models\Attestation;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollBatch;
use App\Services\Payroll\PayrollBatchProofService;
use Carbon\CarbonImmutable;

class PayrollAnchoringService
{
    public function __construct(
        private readonly PayrollAnchorClient $payrollAnchorClient,
        private readonly PayrollBatchProofService $payrollBatchProofService,
    ) {}

    public function syncContract(EmploymentContract $contract): ?string
    {
        $contract->loadMissing(['company', 'employee', 'employee.compensationAmendments', 'latestAttestation']);

        if ($contract->anchor_contract_pubkey) {
            return null;
        }

        $employee = $contract->employee;
        $currentCompensationRef = $this->resolveCurrentCompensationRef($contract, $employee);

        if (! $this->canAnchorContract($employee, $currentCompensationRef)) {
            return __('ui.messages.contract_anchor_pending_prerequisites');
        }

        $result = $this->payrollAnchorClient->createEmploymentContract(
            $contract->company,
            $employee,
            $contract,
            $currentCompensationRef,
        );

        $contract->forceFill([
            'anchor_contract_pubkey' => $result->accountPubkey,
        ])->save();

        $this->upsertAttestation(
            contract: $contract,
            attributes: [
                'attestation_type' => 'employment_contract_anchor',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $this->contractPayloadHash($contract, $employee, $currentCompensationRef),
            ],
        );

        return null;
    }

    public function syncCompensationAmendment(CompensationAmendment $amendment): ?string
    {
        $amendment->loadMissing(['company', 'employee', 'contract', 'latestAttestation']);

        $warning = $this->syncContract($amendment->contract);
        $amendment->contract->refresh();

        if (! $amendment->contract->anchor_contract_pubkey) {
            return $warning;
        }

        if ($amendment->anchor_amendment_pubkey) {
            return null;
        }

        $amendmentHash = $this->amendmentPayloadHash($amendment);

        $result = $this->payrollAnchorClient->amendCompensation(
            $amendment->company,
            $amendment->employee,
            $amendment->contract,
            $amendment,
            $amendmentHash,
        );

        $amendment->forceFill([
            'anchor_amendment_pubkey' => $result->accountPubkey,
        ])->save();

        $this->upsertAttestation(
            compensationAmendment: $amendment,
            contract: $amendment->contract,
            attributes: [
                'attestation_type' => 'compensation_amendment_anchor',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $amendmentHash,
            ],
        );

        return null;
    }

    public function syncCommittedPayrollBatch(PayrollBatch $payrollBatch): ?string
    {
        $payrollBatch->loadMissing([
            'company',
            'entries.employee',
            'entries.compensationAmendment',
            'latestCommitAttestation',
        ]);

        $entries = $payrollBatch->entries
            ->sortBy('id')
            ->values()
            ->map(fn ($entry): array => [
                'employee_id' => $entry->employee_id,
                'compensation_amendment_id' => $entry->compensation_amendment_id,
                'amount_minor' => $entry->amount_minor,
                'currency' => $entry->currency,
                'due_date' => $entry->due_date?->toDateString(),
            ]);
        try {
            $proof = $this->payrollBatchProofService->commitProof($payrollBatch);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        }

        if ($payrollBatch->anchor_batch_pubkey) {
            $latestAttestation = $payrollBatch->latestCommitAttestation;

            if ($latestAttestation && $latestAttestation->payload_hash !== $proof['entries_root']) {
                return __('ui.messages.payroll_batch_committed_and_frozen');
            }

            $payrollBatch->forceFill([
                'entry_count' => $proof['entry_count'],
                'entries_root' => $proof['entries_root'],
            ])->save();

            return null;
        }

        $payrollBatch->forceFill([
            'entry_count' => $proof['entry_count'],
            'entries_root' => $proof['entries_root'],
        ])->save();

        $result = $this->payrollAnchorClient->commitPayrollBatch(
            $payrollBatch->company,
            $payrollBatch,
            $entries,
            $proof['entries_root'],
            $proof['entry_count'],
        );

        $payrollBatch->forceFill([
            'anchor_batch_pubkey' => $result->accountPubkey,
        ])->save();

        $this->upsertAttestation(
            payrollBatch: $payrollBatch,
            attributes: [
                'attestation_type' => 'payroll_batch_commit',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $proof['entries_root'],
            ],
        );

        return null;
    }

    public function syncApprovedPayrollBatch(PayrollBatch $payrollBatch): ?string
    {
        $payrollBatch->loadMissing([
            'company',
            'entries.employee',
            'entries.compensationAmendment',
            'entries.payoutExecution',
            'latestCommitAttestation',
            'latestApprovalAttestation',
        ]);

        $anchorWarning = $this->syncCommittedPayrollBatch($payrollBatch);
        $payrollBatch->refresh()->loadMissing([
            'company',
            'entries.payoutExecution',
            'latestCommitAttestation',
            'latestApprovalAttestation',
        ]);

        if ($anchorWarning) {
            return $anchorWarning;
        }

        if (! $payrollBatch->anchor_batch_pubkey) {
            return __('ui.messages.payroll_batch_commit_pending');
        }

        $proof = $this->payrollBatchProofService->approvalProof($payrollBatch);

        if ($payrollBatch->latestApprovalAttestation) {
            if ($payrollBatch->latestApprovalAttestation->payload_hash !== $proof['approval_root']) {
                return __('ui.messages.payroll_batch_approval_root_mismatch');
            }

            return null;
        }

        $result = $this->payrollAnchorClient->approvePayrollBatch(
            $payrollBatch->company,
            $payrollBatch,
            $proof['approval_root'],
        );

        $payrollBatch->forceFill([
            'approval_root' => $proof['approval_root'],
            'approved_by' => $result->authorityPubkey,
            'approved_at' => $this->chainTimestamp($result->approvedAt),
        ])->save();

        $this->upsertAttestation(
            payrollBatch: $payrollBatch,
            attributes: [
                'attestation_type' => 'payroll_batch_approval',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $proof['approval_root'],
            ],
        );

        return null;
    }

    public function syncFinalizedPayrollBatch(PayrollBatch $payrollBatch): ?string
    {
        $payrollBatch->loadMissing([
            'company',
            'entries.employee',
            'entries.compensationAmendment',
            'entries.payoutExecution',
            'latestCommitAttestation',
            'latestApprovalAttestation',
            'latestFinalizationAttestation',
        ]);

        if ($payrollBatch->status !== PayrollBatch::STATUS_EXECUTED) {
            return null;
        }

        $approvalWarning = $this->syncApprovedPayrollBatch($payrollBatch);
        $payrollBatch->refresh()->loadMissing([
            'company',
            'entries.payoutExecution',
            'latestCommitAttestation',
            'latestApprovalAttestation',
            'latestFinalizationAttestation',
        ]);

        if ($approvalWarning) {
            return $approvalWarning;
        }

        if (! $payrollBatch->anchor_batch_pubkey) {
            return __('ui.messages.payroll_batch_commit_pending_after_reconcile');
        }

        $proof = $this->payrollBatchProofService->settlementProof($payrollBatch);

        if ($payrollBatch->latestFinalizationAttestation) {
            if ($payrollBatch->latestFinalizationAttestation->payload_hash !== $proof['settlement_root']) {
                return __('ui.messages.payroll_batch_finalization_root_mismatch');
            }

            return null;
        }

        $result = $this->payrollAnchorClient->finalizePayrollBatch(
            $payrollBatch->company,
            $payrollBatch,
            $proof['settlement_root'],
        );

        $payrollBatch->forceFill([
            'settlement_root' => $proof['settlement_root'],
            'finalized_by' => $result->finalizedBy ?? $result->authorityPubkey,
            'executed_at' => $this->chainTimestamp($result->executedAt),
        ])->save();

        $this->upsertAttestation(
            payrollBatch: $payrollBatch,
            attributes: [
                'attestation_type' => 'payroll_batch_finalization',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $proof['settlement_root'],
            ],
        );

        return null;
    }

    private function canAnchorContract(Employee $employee, ?string $currentCompensationRef): bool
    {
        return $this->hasEmployeeWallet($employee)
            && is_string($currentCompensationRef)
            && $currentCompensationRef !== '';
    }

    private function hasEmployeeWallet(Employee $employee): bool
    {
        return is_string($employee->wallet_address)
            && trim($employee->wallet_address) !== '';
    }

    private function resolveCurrentCompensationRef(EmploymentContract $contract, Employee $employee): ?string
    {
        $currentCompensation = $employee->currentCompensation($contract->effective_date ?? now());

        return $currentCompensation instanceof CompensationAmendment
            ? $this->amendmentPayloadHash($currentCompensation)
            : null;
    }

    private function contractPayloadHash(
        EmploymentContract $contract,
        Employee $employee,
        string $currentCompensationRef,
    ): string {
        return $this->hashPayload([
            'employee_wallet' => trim((string) $employee->wallet_address),
            'contract_hash' => $contract->file_hash,
            'version' => $contract->version,
            'effective_at' => $contract->effective_date?->startOfDay()->timestamp,
            'pay_cycle' => $employee->pay_cycle,
            'current_compensation_ref' => $currentCompensationRef,
        ]);
    }

    private function amendmentPayloadHash(CompensationAmendment $amendment): string
    {
        $amendment->loadMissing('contract');

        return $this->hashPayload([
            'contract_hash' => $amendment->contract->file_hash,
            'contract_version' => $amendment->contract->version,
            'effective_at' => $amendment->effective_date?->startOfDay()->timestamp,
            'previous_amount_minor' => $amendment->previous_amount_minor,
            'new_amount_minor' => $amendment->new_amount_minor,
            'currency' => $amendment->currency,
            'reason' => $amendment->reason,
        ]);
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function chainTimestamp(?int $timestamp): CarbonImmutable
    {
        if ($timestamp === null || $timestamp <= 0) {
            throw new UserFacingException(__('ui.messages.anchor_batch_account_read_failed'));
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }

    /**
     * @param  array{attestation_type:string,external_id:string,tx_signature:string,payload_hash:string}  $attributes
     */
    private function upsertAttestation(
        array $attributes,
        ?EmploymentContract $contract = null,
        ?CompensationAmendment $compensationAmendment = null,
        ?PayrollBatch $payrollBatch = null,
    ): void {
        $company = $contract?->company ?? $compensationAmendment?->company ?? $payrollBatch?->company;
        $employee = $contract?->employee ?? $compensationAmendment?->employee;

        Attestation::query()->updateOrCreate(
            [
                'attestation_type' => $attributes['attestation_type'],
                'external_id' => $attributes['external_id'],
            ],
            [
                'company_id' => $company?->id,
                'employee_id' => $employee?->id,
                'contract_id' => $contract?->id ?? $compensationAmendment?->contract_id,
                'compensation_amendment_id' => $compensationAmendment?->id,
                'payroll_batch_id' => $payrollBatch?->id,
                'tx_signature' => $attributes['tx_signature'],
                'payload_hash' => $attributes['payload_hash'],
                'issued_at' => now(),
            ],
        );
    }
}
