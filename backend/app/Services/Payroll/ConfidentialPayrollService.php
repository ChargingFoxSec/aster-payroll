<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayoutExecution;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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

        if ($receiptPath) {
            return $this->readStoredJson($receiptPath);
        }

        return $this->readStoredJson($this->legacyReceiptPath());
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
            throw new UserFacingException('Selected employee does not have an effective compensation record for the requested due date.');
        }

        if ($entry->paid_at !== null || $entry->tx_signature !== null) {
            throw new UserFacingException('This payroll entry already has an imported payout receipt.');
        }

        $execution = PayoutExecution::query()->firstOrNew([
            'payroll_entry_id' => $entry->id,
        ]);

        if ($execution->exists && $execution->isImported()) {
            throw new UserFacingException('This payroll entry has already been imported into the ledger.');
        }

        $execution->fill([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'approval_method' => PayoutExecution::APPROVAL_METHOD_LOCAL_SIGNER,
            'status' => PayoutExecution::STATUS_AWAITING_APPROVAL,
            'prepared_payload_path' => $execution->prepared_payload_path ?: $this->preparedPayloadPathForId($execution->id ?? 0),
            'receipt_path' => null,
            'approved_wallet_address' => null,
            'tx_signature' => null,
            'approved_at' => null,
            'imported_at' => null,
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
            throw new UserFacingException('Could not write the payout manifest to private storage.', previous: $throwable);
        }

        if ($stored === false) {
            throw new UserFacingException('Could not write the payout manifest to private storage.');
        }

        $execution->forceFill([
            'prepared_payload_path' => $payloadPath,
        ])->save();

        return $execution->fresh(['company', 'employee', 'payrollEntry.payrollBatch']);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJsonReceipt(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new UserFacingException('Uploaded receipt is not valid JSON.');
        }

        return $decoded;
    }

    public function storeImportedReceipt(PayoutExecution $execution, string $contents): string
    {
        $path = $this->importedReceiptPathForId($execution->id);

        if (Storage::disk('local')->put($path, $contents) === false) {
            throw new UserFacingException('Could not store the imported receipt in private storage.');
        }

        return $path;
    }

    public function manifestDownloadName(PayoutExecution $execution): string
    {
        return "payout-execution-{$execution->id}-manifest.json";
    }

    public function receiptPath(?PayoutExecution $execution = null): ?string
    {
        if ($execution?->receipt_path) {
            return Storage::disk('local')->path($execution->receipt_path);
        }

        $latestExecution = $this->latestExecution();

        if ($latestExecution?->receipt_path) {
            return Storage::disk('local')->path($latestExecution->receipt_path);
        }

        return is_file($this->legacyReceiptPath()) ? $this->legacyReceiptPath() : null;
    }

    public function scriptPath(): string
    {
        return (string) config('payroll.confidential.poc_script');
    }

    public function relativeScriptPath(): string
    {
        return './onchain/scripts/confidential-payroll-poc.sh';
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

    private function legacyReceiptPath(): string
    {
        return (string) config('payroll.confidential.receipt_path');
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
                'token_program_id' => 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb',
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
                'summary' => 'Run the local confidential-transfer helper outside Laravel, using an admin-controlled company signer.',
                'example_command' => sprintf(
                    'ASTER_PAYOUT_MANIFEST=/path/to/%s ASTER_COMPANY_OWNER_KEYPAIR=/absolute/path/to/admin-company-wallet.json %s',
                    $this->manifestDownloadName($execution),
                    $this->relativeScriptPath(),
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
