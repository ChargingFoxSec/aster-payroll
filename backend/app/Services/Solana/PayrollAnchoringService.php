<?php

namespace App\Services\Solana;

use App\Models\Attestation;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollBatch;
use Illuminate\Support\Collection;

class PayrollAnchoringService
{
    public function __construct(
        private readonly PayrollAnchorClient $payrollAnchorClient,
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
            return 'Onchain contract anchoring is pending until the employee wallet address and a baseline compensation record are available.';
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

    public function syncPayrollBatch(PayrollBatch $payrollBatch): ?string
    {
        $payrollBatch->loadMissing([
            'company',
            'entries.employee',
            'entries.compensationAmendment',
            'latestAnchorAttestation',
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
        $batchHash = $this->hashPayload($entries->all());

        if ($payrollBatch->anchor_batch_pubkey) {
            $latestAttestation = $payrollBatch->latestAnchorAttestation;

            if ($latestAttestation && $latestAttestation->payload_hash !== $batchHash) {
                return 'This payroll batch was already anchored onchain; later local draft changes are not reflected in the current onchain batch account yet.';
            }

            return null;
        }

        $result = $this->payrollAnchorClient->createPayrollBatch(
            $payrollBatch->company,
            $payrollBatch,
            $entries,
            $batchHash,
        );

        $payrollBatch->forceFill([
            'anchor_batch_pubkey' => $result->accountPubkey,
        ])->save();

        $this->upsertAttestation(
            payrollBatch: $payrollBatch,
            attributes: [
                'attestation_type' => 'payroll_batch_anchor',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $batchHash,
            ],
        );

        return null;
    }

    public function syncExecutedPayrollBatch(PayrollBatch $payrollBatch): ?string
    {
        $payrollBatch->loadMissing([
            'company',
            'entries.employee',
            'entries.compensationAmendment',
            'latestAnchorAttestation',
            'latestExecutionAttestation',
        ]);

        if ($payrollBatch->status !== PayrollBatch::STATUS_EXECUTED) {
            return null;
        }

        $anchorWarning = $this->syncPayrollBatch($payrollBatch);
        $payrollBatch->refresh()->loadMissing([
            'company',
            'latestAnchorAttestation',
            'latestExecutionAttestation',
        ]);

        if ($anchorWarning) {
            return $anchorWarning;
        }

        if (! $payrollBatch->anchor_batch_pubkey) {
            return __('ui.messages.payroll_batch_anchor_pending_after_reconcile');
        }

        if ($payrollBatch->latestExecutionAttestation) {
            return null;
        }

        $result = $this->payrollAnchorClient->markPayrollBatchExecuted(
            $payrollBatch->company,
            $payrollBatch,
        );

        $this->upsertAttestation(
            payrollBatch: $payrollBatch,
            attributes: [
                'attestation_type' => 'payroll_batch_executed',
                'external_id' => $result->accountPubkey,
                'tx_signature' => $result->txSignature,
                'payload_hash' => $this->hashPayload([
                    'status' => $payrollBatch->status,
                    'executed_at' => $payrollBatch->executed_at?->toIso8601String(),
                ]),
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
