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
        $filters = [
            'period' => trim((string) $request->query('period', '')),
            'status' => trim((string) $request->query('status', '')),
            'employee' => trim((string) $request->query('employee', '')),
            'due_state' => trim((string) $request->query('due_state', '')),
            'tx_or_root' => trim((string) $request->query('tx_or_root', '')),
        ];
        $allowedStatuses = [
            PayrollBatch::STATUS_DRAFT,
            PayrollBatch::STATUS_PENDING,
            PayrollBatch::STATUS_PARTIALLY_PAID,
            PayrollBatch::STATUS_EXECUTED,
            PayrollBatch::STATUS_OVERDUE,
        ];
        $allowedDueStates = ['overdue', 'upcoming'];
        $batchQuery = $company->payrollBatches()
            ->withCount('entries')
            ->with([
                'entries' => fn ($query) => $query->select('id', 'payroll_batch_id', 'employee_id', 'paid_at', 'due_date', 'tx_signature'),
                'entries.employee:id,full_name,email',
                'entries.payoutExecution:id,payroll_entry_id,status,tx_signature',
                'attestations:id,payroll_batch_id,tx_signature,payload_hash',
            ]);

        if (preg_match('/^\d{4}-\d{2}$/', $filters['period']) === 1) {
            [$year, $month] = array_map('intval', explode('-', $filters['period']));
            $batchQuery
                ->where('period_year', $year)
                ->where('period_month', $month);
        }

        if (in_array($filters['status'], $allowedStatuses, true)) {
            $batchQuery->where('status', $filters['status']);
        }

        if ($filters['employee'] !== '') {
            $employeeSearch = '%'.addcslashes($filters['employee'], '%_\\').'%';
            $batchQuery->whereHas('entries.employee', fn ($query) => $query
                ->where('full_name', 'like', $employeeSearch)
                ->orWhere('email', 'like', $employeeSearch));
        }

        if (in_array($filters['due_state'], $allowedDueStates, true)) {
            $today = now()->toDateString();

            $filters['due_state'] === 'overdue'
                ? $batchQuery->whereHas('entries', fn ($query) => $query
                    ->whereNull('paid_at')
                    ->whereDate('due_date', '<', $today))
                : $batchQuery->whereHas('entries', fn ($query) => $query
                    ->whereDate('due_date', '>=', $today));
        }

        if ($filters['tx_or_root'] !== '') {
            $needle = '%'.addcslashes($filters['tx_or_root'], '%_\\').'%';
            $batchQuery->where(function ($query) use ($needle): void {
                $query
                    ->where('anchor_batch_pubkey', 'like', $needle)
                    ->orWhere('entries_root', 'like', $needle)
                    ->orWhere('approval_root', 'like', $needle)
                    ->orWhere('settlement_root', 'like', $needle)
                    ->orWhereHas('attestations', fn ($attestationQuery) => $attestationQuery
                        ->where('tx_signature', 'like', $needle)
                        ->orWhere('payload_hash', 'like', $needle))
                    ->orWhereHas('entries', fn ($entryQuery) => $entryQuery
                        ->where('tx_signature', 'like', $needle)
                        ->orWhereHas('payoutExecution', fn ($executionQuery) => $executionQuery->where('tx_signature', 'like', $needle)));
            });
        }

        return view('payroll.batches.index', [
            'company' => $company,
            'batches' => $batchQuery
                ->orderByDesc('period_year')
                ->orderByDesc('period_month')
                ->get(),
            'filters' => $filters,
            'allowedStatuses' => $allowedStatuses,
            'allowedDueStates' => $allowedDueStates,
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
            'latestCommitAttestation',
            'latestApprovalAttestation',
            'latestFinalizationAttestation',
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
            return $payrollAnchoringService->syncCommittedPayrollBatch($batch);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        } catch (Throwable $throwable) {
            report($throwable);

            return __('ui.messages.payroll_batch_commit_failed');
        }
    }
}
