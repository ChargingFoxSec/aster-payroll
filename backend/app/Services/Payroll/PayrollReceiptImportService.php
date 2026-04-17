<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollReceiptImportService
{
    public function importForExecution(
        PayoutExecution $execution,
        array $receipt,
        ?string $receiptPath = null,
    ): PayrollEntry {
        $execution->loadMissing(['company', 'employee', 'payrollEntry.payrollBatch']);

        if ($execution->isImported()) {
            throw new UserFacingException('This payout execution has already been imported.');
        }

        $txSignature = trim((string) data_get($receipt, 'transactions.confidential_transfer', ''));
        $approvedWalletAddress = trim((string) (
            data_get($receipt, 'approval.approving_wallet_address')
            ?? data_get($receipt, 'actors.company_owner')
            ?? ''
        ));
        $amountMinor = $this->resolveReceiptAmountMinor($receipt);
        $generatedAt = $this->normalizeDateTime(
            data_get($receipt, 'approval.approved_at')
            ?? data_get($receipt, 'generated_at'),
        );

        if ($txSignature === '') {
            throw new UserFacingException('Receipt does not contain a confidential transfer signature.');
        }

        if ($approvedWalletAddress === '') {
            throw new UserFacingException('Receipt does not contain the approving wallet address.');
        }

        if (($receiptExecutionId = data_get($receipt, 'execution.execution_id')) !== null && (int) $receiptExecutionId !== $execution->id) {
            throw new UserFacingException('Receipt execution id does not match the prepared payout.');
        }

        if (($receiptPayrollEntryId = data_get($receipt, 'execution.payroll_entry_id')) !== null && (int) $receiptPayrollEntryId !== $execution->payroll_entry_id) {
            throw new UserFacingException('Receipt payroll entry id does not match the prepared payout.');
        }

        if ($amountMinor !== $execution->payrollEntry->amount_minor) {
            throw new UserFacingException('Receipt amount does not match the prepared payroll entry.');
        }

        if (
            $execution->company->wallet_address !== null
            && $execution->company->wallet_address !== ''
            && $execution->company->wallet_address !== $approvedWalletAddress
        ) {
            throw new UserFacingException('Receipt signer does not match the configured company wallet address.');
        }

        return DB::transaction(function () use (
            $execution,
            $generatedAt,
            $txSignature,
            $approvedWalletAddress,
            $receiptPath,
        ): PayrollEntry {
            $entry = PayrollEntry::query()
                ->with('payrollBatch')
                ->lockForUpdate()
                ->findOrFail($execution->payroll_entry_id);

            if ($entry->tx_signature !== null || $entry->paid_at !== null) {
                throw new UserFacingException('This payroll entry already has an imported payout receipt.');
            }

            $entry->fill([
                'status' => 'paid',
                'paid_at' => $generatedAt,
                'tx_signature' => $txSignature,
            ])->save();

            $batch = PayrollBatch::query()->lockForUpdate()->findOrFail($entry->payroll_batch_id);
            $batch->forceFill([
                'total_amount_minor' => (int) $batch->entries()->sum('amount_minor'),
                'status' => $this->resolveBatchStatus($batch),
                'executed_at' => $generatedAt,
            ])->save();

            $execution->forceFill([
                'status' => PayoutExecution::STATUS_IMPORTED,
                'receipt_path' => $receiptPath,
                'approved_wallet_address' => $approvedWalletAddress,
                'tx_signature' => $txSignature,
                'approved_at' => $generatedAt,
                'imported_at' => now(),
                'failure_reason' => null,
            ])->save();

            return $entry->fresh(['employee', 'payrollBatch', 'payoutExecution']);
        });
    }

    public function importForEmployee(
        Company $company,
        Employee $employee,
        array $receipt,
        DateTimeInterface|string $dueDate,
    ): PayrollEntry {
        if ($employee->company_id !== $company->id) {
            throw new InvalidArgumentException('Employee does not belong to the selected demo company.');
        }

        $amountMinor = $this->resolveReceiptAmountMinor($receipt);
        $txSignature = data_get($receipt, 'transactions.confidential_transfer');
        $approvedWalletAddress = data_get($receipt, 'approval.approving_wallet_address')
            ?? data_get($receipt, 'actors.company_owner');
        $dueDate = $this->normalizeDate($dueDate);
        $generatedAt = $this->normalizeDateTime(data_get($receipt, 'generated_at'));

        return DB::transaction(function () use (
            $company,
            $employee,
            $amountMinor,
            $dueDate,
            $generatedAt,
            $txSignature,
            $approvedWalletAddress,
        ): PayrollEntry {
            $amendment = $this->resolveEffectiveCompensation($employee, $dueDate);

            $batch = PayrollBatch::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'period_year' => $dueDate->year,
                    'period_month' => $dueDate->month,
                ],
                [
                    'currency' => $employee->currency,
                    'due_date' => $dueDate->toDateString(),
                    'executed_at' => $generatedAt,
                ],
            );

            $entry = PayrollEntry::query()->firstOrNew(
                [
                    'payroll_batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                ],
            );

            $entry->fill([
                'amount_minor' => $amountMinor,
                'currency' => $employee->currency,
                'status' => $txSignature ? 'paid' : 'pending',
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $txSignature ? $generatedAt : null,
                'tx_signature' => $txSignature,
            ]);

            if ($amendment instanceof CompensationAmendment) {
                $entry->compensation_amendment_id = $amendment->id;
            }

            $entry->save();

            $batch->forceFill([
                'total_amount_minor' => (int) $batch->entries()->sum('amount_minor'),
                'status' => $this->resolveBatchStatus($batch),
                'executed_at' => $generatedAt,
            ])->save();

            $entry->payoutExecution?->forceFill([
                'status' => $txSignature ? PayoutExecution::STATUS_IMPORTED : PayoutExecution::STATUS_AWAITING_APPROVAL,
                'approved_wallet_address' => is_string($approvedWalletAddress) ? $approvedWalletAddress : null,
                'tx_signature' => is_string($txSignature) ? $txSignature : null,
                'approved_at' => $txSignature ? $generatedAt : null,
                'imported_at' => $txSignature ? now() : null,
                'failure_reason' => null,
            ])->save();

            return $entry->fresh(['employee', 'payrollBatch']);
        });
    }

    private function normalizeDate(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format(DATE_ATOM))->startOfDay();
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function normalizeDateTime(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format(DATE_ATOM));
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return CarbonImmutable::now();
    }

    private function resolveReceiptAmountMinor(array $receipt): int
    {
        $amountMinor = data_get($receipt, 'payroll.amount_minor');

        if (is_numeric($amountMinor)) {
            return (int) $amountMinor;
        }

        $transferAmount = data_get($receipt, 'payroll.confidential_transfer_amount');
        $decimals = (int) data_get($receipt, 'token.decimals', 0);

        if (! is_numeric($transferAmount)) {
            throw new UserFacingException('Receipt does not contain a numeric confidential transfer amount.');
        }

        return (int) round((float) $transferAmount * (10 ** $decimals));
    }

    private function resolveBatchStatus(PayrollBatch $batch): string
    {
        $entries = $batch->entries()->get(['paid_at']);

        if ($entries->isEmpty()) {
            return 'draft';
        }

        if ($entries->every(fn (PayrollEntry $entry) => $entry->paid_at !== null)) {
            return 'executed';
        }

        if ($entries->contains(fn (PayrollEntry $entry) => $entry->paid_at !== null)) {
            return 'partially_paid';
        }

        return 'pending';
    }

    private function resolveEffectiveCompensation(Employee $employee, CarbonImmutable $dueDate): ?CompensationAmendment
    {
        return $employee->compensationAmendments()
            ->whereDate('effective_date', '<=', $dueDate->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }
}
