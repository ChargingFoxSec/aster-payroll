<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ConfidentialPayrollService
{
    public function __construct(
        private readonly PayrollBatchDraftService $payrollBatchDraftService,
    ) {}

    public function latestExecution(?Company $company = null): ?PayoutExecution
    {
        return PayoutExecution::query()
            ->when(
                $company,
                fn ($query) => $query->where('company_id', $company->id),
            )
            ->with(['employee', 'payrollEntry.payrollBatch'])
            ->latest('updated_at')
            ->first();
    }

    public function latestReceipt(?Company $company = null): ?array
    {
        $execution = $this->latestExecution($company);
        $receiptPath = $execution?->receipt_path;

        return $receiptPath ? $this->readStoredJson($receiptPath) : null;
    }

    /**
     * @return Collection<int, PayoutExecution>
     */
    public function prepareBatchExecutions(
        Company $company,
        PayrollBatch $payrollBatch,
    ): Collection {
        if ($payrollBatch->company_id !== $company->id) {
            throw new InvalidArgumentException('Payroll batch does not belong to the selected company.');
        }

        $payrollBatch->loadMissing([
            'latestFinalizationAttestation',
            'entries' => fn ($query) => $query
                ->with(['employee', 'payrollBatch.latestApprovalAttestation', 'payoutExecution'])
                ->orderBy('id'),
        ]);

        if ($payrollBatch->latestFinalizationAttestation) {
            throw new UserFacingException(__('ui.messages.payroll_batch_finalized_and_frozen'));
        }

        if ($payrollBatch->entries->isEmpty()) {
            throw new UserFacingException(__('ui.messages.batch_has_no_entries'));
        }

        $preparedExecutions = $payrollBatch->entries
            ->filter(fn ($entry) => $entry->paid_at === null && $entry->tx_signature === null)
            ->map(fn ($entry) => $this->prepareEntryExecution($company, $entry))
            ->filter();

        if ($preparedExecutions->isEmpty()) {
            throw new UserFacingException(__('ui.messages.batch_all_entries_imported'));
        }

        return $preparedExecutions->values();
    }

    public function prepareExecution(
        Company $company,
        Employee $employee,
        DateTimeInterface|string $dueDate,
    ): PayoutExecution {
        if ($employee->company_id !== $company->id) {
            throw new InvalidArgumentException('Employee does not belong to the selected company.');
        }

        $normalizedDueDate = $this->normalizeDate($dueDate);
        $batch = $this->payrollBatchDraftService->createOrRefresh(
            $company,
            $normalizedDueDate->format('Y-m'),
            $normalizedDueDate,
        );
        $entry = $batch->entries()
            ->with(['employee', 'payrollBatch', 'payoutExecution'])
            ->where('employee_id', $employee->id)
            ->first();

        if ($entry === null) {
            throw new UserFacingException(__('ui.messages.missing_effective_compensation_for_due_date'));
        }

        return $this->prepareEntryExecution($company, $entry);
    }

    private function prepareEntryExecution(Company $company, PayrollEntry $entry): PayoutExecution
    {
        if ($entry->paid_at !== null || $entry->tx_signature !== null) {
            throw new UserFacingException(__('ui.messages.payroll_entry_has_imported_receipt'));
        }

        $execution = PayoutExecution::query()->firstOrNew([
            'payroll_entry_id' => $entry->id,
        ]);

        if ($execution->exists && $execution->isImported()) {
            throw new UserFacingException(__('ui.messages.payroll_entry_already_imported_to_ledger'));
        }

        if ($entry->payrollBatch?->latestApprovalAttestation && $execution->exists) {
            if ($this->hasReusablePreparedPayload($execution)) {
                return $execution->fresh(['company', 'employee', 'payrollEntry.payrollBatch']) ?? $execution;
            }

            throw new UserFacingException(__('ui.messages.payroll_batch_approved_and_frozen'));
        }

        $execution->fill([
            'company_id' => $company->id,
            'employee_id' => $entry->employee_id,
            'approval_method' => PayoutExecution::APPROVAL_METHOD_LOCAL_SIGNER,
            'status' => PayoutExecution::STATUS_AWAITING_APPROVAL,
            'prepared_payload_path' => $execution->prepared_payload_path ?: '',
            'prepared_payload_hash' => null,
            'receipt_path' => null,
            'receipt_hash' => null,
            'approved_wallet_address' => null,
            'tx_signature' => null,
            'approved_at' => null,
            'imported_at' => null,
            'receipt_verified_at' => null,
            'failure_reason' => null,
        ]);
        $execution->payrollEntry()->associate($entry);
        $execution->save();

        $execution = $execution->fresh(['company', 'employee', 'payrollEntry.payrollBatch']);
        $payloadPath = $this->preparedPayloadPathForId($execution->id);
        $payload = $this->buildPreparedPayload($execution);

        try {
            $payloadContents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $stored = Storage::disk('local')->put($payloadPath, $payloadContents);
        } catch (Throwable $throwable) {
            throw new UserFacingException(__('ui.messages.payout_manifest_store_failed'), previous: $throwable);
        }

        if ($stored === false) {
            throw new UserFacingException(__('ui.messages.payout_manifest_store_failed'));
        }

        $execution->forceFill([
            'prepared_payload_path' => $payloadPath,
            'prepared_payload_hash' => hash('sha256', $payloadContents),
        ])->save();

        return $execution->fresh(['company', 'employee', 'payrollEntry.payrollBatch']);
    }

    private function hasReusablePreparedPayload(PayoutExecution $execution): bool
    {
        return is_string($execution->prepared_payload_path)
            && $execution->prepared_payload_path !== ''
            && is_string($execution->prepared_payload_hash)
            && $execution->prepared_payload_hash !== ''
            && Storage::disk('local')->exists($execution->prepared_payload_path)
            && hash_equals(
                $execution->prepared_payload_hash,
                hash('sha256', Storage::disk('local')->get($execution->prepared_payload_path)),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJsonReceipt(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new UserFacingException(__('ui.messages.receipt_invalid_json'));
        }

        return $decoded;
    }

    public function storeImportedReceipt(PayoutExecution $execution, string $contents): string
    {
        $path = $this->importedReceiptPathForId($execution->id);

        if (Storage::disk('local')->put($path, $contents) === false) {
            throw new UserFacingException(__('ui.messages.receipt_store_failed'));
        }

        return $path;
    }

    public function manifestDownloadName(PayoutExecution $execution): string
    {
        return "payout-execution-{$execution->id}-manifest.json";
    }

    public function scriptPath(): string
    {
        return (string) config('payroll.confidential.poc_script');
    }

    public function relativeScriptPath(): string
    {
        return 'cd onchain && yarn signer';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStoredJson(string $path): ?array
    {
        $contents = null;

        if (str_starts_with($path, storage_path())) {
            if (! is_file($path)) {
                return null;
            }

            $contents = file_get_contents($path);
        } elseif (Storage::disk('local')->exists($path)) {
            $contents = Storage::disk('local')->get($path);
        }

        if (! is_string($contents)) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function preparedPayloadPathForId(int $executionId): string
    {
        return trim((string) config('payroll.confidential.prepared_payload_dir'), '/')."/execution-{$executionId}.json";
    }

    private function importedReceiptPathForId(int $executionId): string
    {
        return trim((string) config('payroll.confidential.imported_receipt_dir'), '/')."/execution-{$executionId}-receipt.json";
    }

    private function mintDecimals(): int
    {
        return (int) config('payroll.confidential.mint_decimals', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreparedPayload(PayoutExecution $execution): array
    {
        $entry = $execution->payrollEntry;
        $batch = $entry->payrollBatch;
        $decimals = $this->mintDecimals();

        return [
            'generated_at' => now()->toIso8601String(),
            'execution' => [
                'execution_id' => $execution->id,
                'payroll_entry_id' => $entry->id,
                'payroll_batch_id' => $batch->id,
                'approval_method' => $execution->approval_method,
                'status' => $execution->status,
            ],
            'network' => [
                'rpc_url' => config('payroll.confidential.rpc_url'),
                'token_program_id' => config('payroll.confidential.token_program_id'),
            ],
            'company' => [
                'id' => $execution->company->id,
                'name' => $execution->company->name,
                'wallet_address' => $execution->company->wallet_address,
            ],
            'employee' => [
                'id' => $execution->employee->id,
                'full_name' => $execution->employee->full_name,
                'email' => $execution->employee->email,
                'wallet_address' => $execution->employee->wallet_address,
            ],
            'payroll' => [
                'period_year' => $batch->period_year,
                'period_month' => $batch->period_month,
                'due_date' => $entry->due_date->toDateString(),
                'currency' => $entry->currency,
                'amount_minor' => $entry->amount_minor,
                'mint_decimals' => $decimals,
                'confidential_transfer_amount' => $entry->amount_minor / (10 ** $decimals),
            ],
            'artifacts' => [
                'manifest_download_name' => $this->manifestDownloadName($execution),
                'receipt_file_hint' => "execution-{$execution->id}-receipt.json",
                'helper_script' => $this->relativeScriptPath(),
            ],
            'instructions' => [
                'summary' => 'Run the Anchor-side confidential-transfer signer outside Laravel, using an admin-controlled company signer.',
                'example_command' => sprintf(
                    'cd onchain && ASTER_PAYOUT_MANIFEST=/path/to/%s ASTER_COMPANY_OWNER_KEYPAIR=/absolute/path/to/admin-company-wallet.json yarn signer',
                    $this->manifestDownloadName($execution),
                ),
            ],
        ];
    }

    private function normalizeDate(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format(DATE_ATOM))->startOfDay();
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
