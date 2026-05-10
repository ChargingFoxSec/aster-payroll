<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Payroll\PayrollBatchProofService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeePortalController extends Controller
{
    public function show(Request $request): View
    {
        $company = $this->currentCompany($request);
        $employee = $this->currentEmployee($request);

        $this->authorize('view', $employee);

        $employee->load([
            'contracts' => fn ($query) => $query->latest('version'),
            'payrollEntries' => fn ($query) => $query
                ->with('payrollBatch')
                ->orderByDesc('due_date')
                ->orderByDesc('id')
                ->limit(5),
        ]);

        $currentCompensation = $employee->currentCompensation();

        return view('portal.show', [
            'company' => $company,
            'employee' => $employee,
            'currentCompensation' => $currentCompensation,
            'latestContract' => $employee->contracts->first(),
            'payCycles' => [
                'monthly' => __('ui.pay_cycles.monthly'),
                'semi_monthly' => __('ui.pay_cycles.semi_monthly'),
                'bi_weekly' => __('ui.pay_cycles.bi_weekly'),
            ],
        ]);
    }

    public function payroll(Request $request, PayrollBatchProofService $payrollBatchProofService): View
    {
        $company = $this->currentCompany($request);
        $employee = $this->currentEmployee($request);

        $this->authorize('view', $employee);

        $employee->load([
            'payrollEntries' => fn ($query) => $query
                ->with([
                    'payrollBatch.latestCommitAttestation',
                    'payrollBatch.latestApprovalAttestation',
                    'payrollBatch.latestFinalizationAttestation',
                    'payoutExecution',
                    'proof',
                ])
                ->orderByDesc('due_date')
                ->orderByDesc('id'),
        ]);

        return view('employees.payroll', [
            'company' => $company,
            'employee' => $employee,
            'proofVerifications' => $this->proofVerifications($employee, $payrollBatchProofService),
            'backUrl' => route('portal.show'),
            'backLabel' => __('ui.actions.back_to_portal'),
            'scopeLabel' => __('ui.pages.portal.self_service_short'),
        ]);
    }

    /**
     * @return array<int, bool>
     */
    private function proofVerifications(Employee $employee, PayrollBatchProofService $payrollBatchProofService): array
    {
        return $employee->payrollEntries
            ->filter(fn ($entry): bool => $entry->proof !== null)
            ->mapWithKeys(fn ($entry): array => [
                $entry->id => $payrollBatchProofService->verifyMembership(
                    $entry->proof,
                    $entry->payrollBatch->entries_root,
                ),
            ])
            ->all();
    }
}
