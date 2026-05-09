<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Services\Solana\ConfidentialTransferReceiptVerifier;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PayrollReceiptImportService
{
    public function __construct(
        private readonly PayrollStatusService $payrollStatusService,
        private readonly ConfidentialTransferReceiptVerifier $confidentialTransferReceiptVerifier,
    ) {}

    public function importForExecution(
        PayoutExecution $execution,
        array $receipt,
        ?string $receiptPath = null,
    ): PayrollEntry {
        $execution->loadMissing(['company', 'employee', 'payrollEntry.payrollBatch.latestFinalizationAttestation']);

        if ($execution->isImported()) {
            throw new UserFacingException(__('ui.messages.payout_execution_already_imported'));
        }

        if ($execution->payrollEntry->payrollBatch->latestFinalizationAttestation) {
            throw new UserFacingException(__('ui.messages.payroll_batch_finalized_and_frozen'));
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

        if ($txSignature === '' && $this->looksLikePreparedManifestUpload($receipt)) {
            throw new UserFacingException(__('ui.messages.receipt_upload_is_manifest'));
        }

        if ($txSignature === '') {
            throw new UserFacingException(__('ui.messages.receipt_missing_confidential_transfer_signature'));
        }

        if ($approvedWalletAddress === '') {
            throw new UserFacingException(__('ui.messages.receipt_missing_approving_wallet'));
        }

        if (($receiptExecutionId = data_get($receipt, 'execution.execution_id')) !== null && (int) $receiptExecutionId !== $execution->id) {
            throw new UserFacingException(__('ui.messages.receipt_execution_id_mismatch'));
        }

        if (($receiptPayrollEntryId = data_get($receipt, 'execution.payroll_entry_id')) !== null && (int) $receiptPayrollEntryId !== $execution->payroll_entry_id) {
            throw new UserFacingException(__('ui.messages.receipt_payroll_entry_id_mismatch'));
        }

        if ($amountMinor !== $execution->payrollEntry->amount_minor) {
            throw new UserFacingException(__('ui.messages.receipt_amount_mismatch'));
        }

        if (
            $execution->company->wallet_address !== null
            && $execution->company->wallet_address !== ''
            && $execution->company->wallet_address !== $approvedWalletAddress
        ) {
            throw new UserFacingException(__('ui.messages.receipt_signer_mismatch'));
        }

        $this->assertReceiptMatchesPreparedManifest($execution, $receipt);
        $this->confidentialTransferReceiptVerifier->verify($execution, $receipt);
        $receiptHash = $this->receiptHash($receipt, $receiptPath);

        return DB::transaction(function () use (
            $execution,
            $generatedAt,
            $txSignature,
            $approvedWalletAddress,
            $receiptPath,
            $receiptHash,
        ): PayrollEntry {
            $entry = PayrollEntry::query()
                ->with('payrollBatch')
                ->lockForUpdate()
                ->findOrFail($execution->payroll_entry_id);

            if ($entry->tx_signature !== null || $entry->paid_at !== null) {
                throw new UserFacingException(__('ui.messages.payroll_entry_has_imported_receipt'));
            }

            $entry->fill([
                'status' => 'paid',
                'paid_at' => $generatedAt,
                'tx_signature' => $txSignature,
            ])->save();

            $batch = PayrollBatch::query()->lockForUpdate()->findOrFail($entry->payroll_batch_id);
            $this->payrollStatusService->syncLoadedBatch(
                $batch,
                $batch->entries()->orderBy('id')->get(),
                $generatedAt,
            );

            $execution->forceFill([
                'status' => PayoutExecution::STATUS_IMPORTED,
                'receipt_path' => $receiptPath,
                'receipt_hash' => $receiptHash,
                'approved_wallet_address' => $approvedWalletAddress,
                'tx_signature' => $txSignature,
                'approved_at' => $generatedAt,
                'imported_at' => now(),
                'receipt_verified_at' => now(),
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
        $supportedCurrency = $this->supportedCurrency();

        $this->assertSupportedCurrency(
            $employee->currency,
            __('ui.messages.employee_currency_unsupported_for_receipt_import', ['currency' => $supportedCurrency]),
        );

        return DB::transaction(function () use (
            $company,
            $employee,
            $amountMinor,
            $dueDate,
            $generatedAt,
            $txSignature,
            $approvedWalletAddress,
            $supportedCurrency,
        ): PayrollEntry {
            $amendment = $employee->effectiveCompensationAt($dueDate);

            if ($amendment !== null) {
                $this->assertSupportedCurrency(
                    $amendment->currency,
                    __('ui.messages.compensation_currency_unsupported_for_receipt_import', ['currency' => $supportedCurrency]),
                );
            }

            $batch = PayrollBatch::query()->firstOrNew([
                'company_id' => $company->id,
                'period_year' => $dueDate->year,
                'period_month' => $dueDate->month,
            ]);

            if ($batch->exists && $batch->anchor_batch_pubkey !== null) {
                throw new UserFacingException(__('ui.messages.payroll_batch_committed_and_frozen'));
            }

            $batch->fill([
                'currency' => $supportedCurrency,
                'due_date' => $dueDate->toDateString(),
            ])->save();

            $entry = PayrollEntry::query()->firstOrNew(
                [
                    'payroll_batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                ],
            );

            $entry->fill([
                'amount_minor' => $amountMinor,
                'currency' => $supportedCurrency,
                'status' => $txSignature ? 'paid' : 'pending',
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $txSignature ? $generatedAt : null,
                'tx_signature' => $txSignature,
            ]);

            if ($amendment instanceof CompensationAmendment) {
                $entry->compensation_amendment_id = $amendment->id;
            }

            $entry->save();

            $this->payrollStatusService->syncLoadedBatch(
                $batch,
                $batch->entries()->orderBy('id')->get(),
                $generatedAt,
            );

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
            throw new UserFacingException(__('ui.messages.receipt_amount_not_numeric'));
        }

        return (int) round((float) $transferAmount * (10 ** $decimals));
    }

    private function assertReceiptMatchesPreparedManifest(PayoutExecution $execution, array $receipt): void
    {
        $expectedHash = $this->preparedManifestHash($execution);
        $receiptHash = trim((string) (
            data_get($receipt, 'approval.prepared_manifest_hash')
            ?? data_get($receipt, 'artifacts.prepared_manifest_hash')
            ?? ''
        ));

        if ($receiptHash === '') {
            throw new UserFacingException(__('ui.messages.receipt_manifest_hash_missing'));
        }

        if (! hash_equals($expectedHash, $receiptHash)) {
            throw new UserFacingException(__('ui.messages.receipt_manifest_hash_mismatch'));
        }
    }

    private function looksLikePreparedManifestUpload(array $receipt): bool
    {
        return data_get($receipt, 'artifacts.manifest_download_name') !== null
            || data_get($receipt, 'artifacts.receipt_file_hint') !== null
            || data_get($receipt, 'instructions.example_command') !== null;
    }

    private function preparedManifestHash(PayoutExecution $execution): string
    {
        $hash = trim((string) $execution->prepared_payload_hash);

        if ($hash !== '') {
            return $hash;
        }

        if (
            is_string($execution->prepared_payload_path)
            && $execution->prepared_payload_path !== ''
            && Storage::disk('local')->exists($execution->prepared_payload_path)
        ) {
            $hash = hash('sha256', Storage::disk('local')->get($execution->prepared_payload_path));
            $execution->forceFill(['prepared_payload_hash' => $hash])->save();

            return $hash;
        }

        throw new UserFacingException(__('ui.messages.receipt_manifest_hash_missing'));
    }

    private function receiptHash(array $receipt, ?string $receiptPath): string
    {
        if (is_string($receiptPath) && $receiptPath !== '' && Storage::disk('local')->exists($receiptPath)) {
            return hash('sha256', Storage::disk('local')->get($receiptPath));
        }

        return hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function supportedCurrency(): string
    {
        return (string) config('payroll.currency.code', 'USDC');
    }

    private function assertSupportedCurrency(string $currency, string $message): void
    {
        if ($currency !== $this->supportedCurrency()) {
            throw new UserFacingException($message);
        }
    }
}
