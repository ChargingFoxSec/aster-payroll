<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Payroll\PayrollBatchProofService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeePayrollController extends Controller
{
    public function show(
        Request $request,
        Employee $employee,
        PayrollBatchProofService $payrollBatchProofService,
    ): View {
        $this->authorize('view', $employee);

        $company = $this->currentCompany($request);

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
            'backUrl' => route('employees.show', $employee),
            'backLabel' => __('ui.actions.back_to_employee'),
            'scopeLabel' => __('ui.pages.employees.detail_kicker'),
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
