<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\StorePayrollBatchRequest;
use App\Models\PayrollBatch;
use App\Services\Payroll\PayrollBatchDraftService;
use App\Services\Solana\PayrollAnchoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PayrollBatchController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PayrollBatch::class);

        $company = $this->currentCompany($request);

        return view('payroll.batches.index', [
            'company' => $company,
            'batches' => $company->payrollBatches()
                ->withCount('entries')
                ->with([
                    'entries' => fn ($query) => $query->select('id', 'payroll_batch_id', 'paid_at', 'due_date'),
                    'entries.payoutExecution:id,payroll_entry_id,status',
                ])
                ->orderByDesc('period_year')
                ->orderByDesc('period_month')
                ->get(),
            'defaultPeriod' => now()->format('Y-m'),
            'defaultDueDate' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function store(
        StorePayrollBatchRequest $request,
        PayrollBatchDraftService $payrollBatchDraftService,
        PayrollAnchoringService $payrollAnchoringService,
    ): RedirectResponse {
        $this->authorize('create', PayrollBatch::class);

        $company = $this->currentCompany($request);
        $validated = $request->validated();

        try {
            $batch = $payrollBatchDraftService->createOrRefresh(
                $company,
                $validated['period'],
                $validated['due_date'],
            );
        } catch (UserFacingException $userFacingException) {
            return redirect()
                ->route('payroll-batches.index')
                ->withInput()
                ->with('error', $userFacingException->getMessage());
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('payroll-batches.index')
                ->withInput()
                ->with('error', __('ui.messages.payroll_batch_draft_failed'));
        }

        $anchorWarning = $this->syncBatchWarning($payrollAnchoringService, $batch);

        return redirect()
            ->route('payroll-batches.show', $batch)
            ->with('status', trim(__('ui.messages.payroll_batch_drafted', [
                'period' => "{$batch->period_year}-".str_pad((string) $batch->period_month, 2, '0', STR_PAD_LEFT),
            ]).($anchorWarning ? " {$anchorWarning}" : '')));
    }

    public function show(Request $request, PayrollBatch $payrollBatch): View
    {
        $this->authorize('view', $payrollBatch);

        $company = $this->currentCompany($request);

        $payrollBatch->load([
            'entries' => fn ($query) => $query
                ->with(['employee', 'compensationAmendment', 'payoutExecution'])
                ->orderBy('due_date')
                ->orderBy('id'),
            'latestAnchorAttestation',
            'latestExecutionAttestation',
        ]);

        return view('payroll.batches.show', [
            'company' => $company,
            'payrollBatch' => $payrollBatch,
        ]);
    }

    private function syncBatchWarning(
        PayrollAnchoringService $payrollAnchoringService,
        PayrollBatch $batch,
    ): ?string {
        try {
            return $payrollAnchoringService->syncPayrollBatch($batch);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        } catch (Throwable $throwable) {
            report($throwable);

            return __('ui.messages.payroll_batch_anchor_failed');
        }
    }
}
