<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\ImportPayoutReceiptRequest;
use App\Http\Requests\PreparePayoutExecutionRequest;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Services\Payroll\ConfidentialPayrollService;
use App\Services\Payroll\PayrollReceiptImportService;
use App\Services\Solana\PayrollAnchoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PayrollDemoController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorize('viewAny', PayoutExecution::class);

        $company = $this->currentCompany($request);
        $batches = $company->payrollBatches()
            ->withCount('entries')
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();
        $selectedBatchId = $request->integer('payroll_batch_id');
        $selectedBatch = $batches->firstWhere('id', $selectedBatchId)
            ?? $batches->first(fn (PayrollBatch $batch) => $batch->entries_count > 0)
            ?? $batches->first();

        if ($selectedBatch) {
            $selectedBatch->load([
                'entries' => fn ($query) => $query
                    ->with(['employee', 'payoutExecution.payrollEntry'])
                    ->orderBy('id'),
                'latestCommitAttestation',
                'latestApprovalAttestation',
                'latestFinalizationAttestation',
            ]);
        }

        return view('payroll.demo', [
            'company' => $company,
            'batches' => $batches,
            'selectedBatch' => $selectedBatch,
            'receiptSummaries' => $this->receiptSummariesForBatch($selectedBatch),
        ]);
    }

    public function prepare(
        PreparePayoutExecutionRequest $request,
        ConfidentialPayrollService $confidentialPayrollService,
        PayrollAnchoringService $payrollAnchoringService,
    ): RedirectResponse|StreamedResponse {
        $company = $this->currentCompany($request);
        $validated = $request->validated();
        $batch = $company->payrollBatches()->findOrFail($validated['payroll_batch_id']);

        try {
            $preparedExecutions = $confidentialPayrollService->prepareBatchExecutions(
                $company,
                $batch,
            );
            $zipContents = $confidentialPayrollService->manifestZipForExecutions($preparedExecutions);
        } catch (UserFacingException $userFacingException) {
            return redirect()
                ->route('payroll-demo.show', ['payroll_batch_id' => $batch->id])
                ->withInput()
                ->with('error', $userFacingException->getMessage());
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('payroll-demo.show', ['payroll_batch_id' => $batch->id])
                ->withInput()
                ->with('error', __('ui.messages.prepare_manifest_failed'));
        }

        $approvalWarning = $this->syncApprovedBatchWarning($payrollAnchoringService, $batch->fresh());
        $request->session()->flash(
            'status',
            trim(__('ui.messages.prepared_manifests', [
                'count' => $preparedExecutions->count(),
                'period' => sprintf('%d-%02d', $batch->period_year, $batch->period_month),
            ]).($approvalWarning ? " {$approvalWarning}" : '')),
        );

        return response()->streamDownload(
            static function () use ($zipContents): void {
                echo $zipContents;
            },
            $confidentialPayrollService->manifestZipDownloadName($batch),
            ['Content-Type' => 'application/zip'],
        );
    }

    public function import(
        ImportPayoutReceiptRequest $request,
        ConfidentialPayrollService $confidentialPayrollService,
        PayrollReceiptImportService $payrollReceiptImportService,
        PayrollAnchoringService $payrollAnchoringService,
    ): RedirectResponse {
        $company = $this->currentCompany($request);
        $validated = $request->validated();

        $execution = $company->payoutExecutions()
            ->with(['employee', 'payrollEntry.payrollBatch'])
            ->findOrFail($validated['payout_execution_id']);
        $this->authorize('import', $execution);

        try {
            $contents = $request->file('receipt')->get();

            if (! is_string($contents) || $contents === '') {
                throw new UserFacingException(__('ui.messages.receipt_unreadable'));
            }

            $receipt = $confidentialPayrollService->decodeJsonReceipt($contents);
            $receiptPath = $confidentialPayrollService->storeImportedReceipt($execution, $contents);
            $entry = $payrollReceiptImportService->importForExecution($execution, $receipt, $receiptPath);
        } catch (UserFacingException $userFacingException) {
            $this->markExecutionFailure($execution, $userFacingException->getMessage());

            return redirect()
                ->route('payroll-demo.show', ['payroll_batch_id' => $execution->payrollEntry->payroll_batch_id])
                ->withInput()
                ->with('error', $userFacingException->getMessage());
        } catch (Throwable $throwable) {
            report($throwable);

            $message = __('ui.messages.receipt_import_failed');
            $this->markExecutionFailure($execution, $message);

            return redirect()
                ->route('payroll-demo.show', ['payroll_batch_id' => $execution->payrollEntry->payroll_batch_id])
                ->withInput()
                ->with('error', $message);
        }

        $batch = $entry->payrollBatch->fresh([
            'latestCommitAttestation',
            'latestApprovalAttestation',
            'latestFinalizationAttestation',
        ]);
        $executionWarning = $this->syncFinalizedBatchWarning($payrollAnchoringService, $batch);

        return redirect()
            ->route('payroll-demo.show', ['payroll_batch_id' => $batch->id])
            ->with(
                'status',
                trim(implode(' ', array_filter([
                    __('ui.messages.receipt_imported', [
                        'employee' => $execution->employee->full_name,
                        'wallet' => $execution->fresh()->approved_wallet_address,
                    ]),
                    $batch->status === PayrollBatch::STATUS_EXECUTED
                        ? __('ui.messages.batch_reconciled', [
                            'period' => sprintf('%d-%02d', $batch->period_year, $batch->period_month),
                        ])
                        : null,
                    $executionWarning,
                ]))),
            );
    }

    public function downloadManifest(
        PayoutExecution $payoutExecution,
        ConfidentialPayrollService $confidentialPayrollService,
    ): StreamedResponse {
        $this->authorize('downloadManifest', $payoutExecution);

        abort_unless(
            $payoutExecution->prepared_payload_path !== null
            && Storage::disk('local')->exists($payoutExecution->prepared_payload_path),
            404,
        );

        return Storage::disk('local')->download(
            $payoutExecution->prepared_payload_path,
            $confidentialPayrollService->manifestDownloadName($payoutExecution),
            ['Content-Type' => 'application/json'],
        );
    }

    private function markExecutionFailure(PayoutExecution $execution, string $message): void
    {
        $execution->forceFill([
            'status' => PayoutExecution::STATUS_FAILED,
            'failure_reason' => $message,
        ])->save();
    }

    /**
     * @return array<int, array{amount_minor:?int,confidential_transfer_amount:mixed,employee_public_balance:mixed}>
     */
    private function receiptSummariesForBatch(?PayrollBatch $batch): array
    {
        if (! $batch) {
            return [];
        }

        $summaries = [];

        foreach ($batch->entries as $entry) {
            $execution = $entry->payoutExecution;

            if (! $execution?->receipt_path || ! Storage::disk('local')->exists($execution->receipt_path)) {
                continue;
            }

            $receipt = json_decode(Storage::disk('local')->get($execution->receipt_path), true);

            if (! is_array($receipt)) {
                continue;
            }

            $summaries[$execution->id] = [
                'amount_minor' => is_numeric(data_get($receipt, 'payroll.amount_minor'))
                    ? (int) data_get($receipt, 'payroll.amount_minor')
                    : null,
                'confidential_transfer_amount' => data_get($receipt, 'payroll.confidential_transfer_amount'),
                'employee_public_balance' => data_get($receipt, 'balances.employee_public_balance'),
            ];
        }

        return $summaries;
    }

    private function syncApprovedBatchWarning(
        PayrollAnchoringService $payrollAnchoringService,
        PayrollBatch $batch,
    ): ?string {
        try {
            return $payrollAnchoringService->syncApprovedPayrollBatch($batch);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        } catch (Throwable $throwable) {
            report($throwable);

            return __('ui.messages.payroll_batch_approval_attestation_failed');
        }
    }

    private function syncFinalizedBatchWarning(
        PayrollAnchoringService $payrollAnchoringService,
        PayrollBatch $batch,
    ): ?string {
        try {
            return $payrollAnchoringService->syncFinalizedPayrollBatch($batch);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        } catch (Throwable $throwable) {
            report($throwable);

            return __('ui.messages.payroll_batch_finalization_attestation_failed');
        }
    }
}
