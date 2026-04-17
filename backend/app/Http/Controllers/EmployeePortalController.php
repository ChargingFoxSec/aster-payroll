<?php

namespace App\Http\Controllers;

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
            'compensationAmendments' => fn ($query) => $query
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->limit(1),
            'payrollEntries' => fn ($query) => $query
                ->with('payrollBatch')
                ->orderByDesc('due_date')
                ->orderByDesc('id')
                ->limit(5),
        ]);

        $currentCompensation = $employee->compensationAmendments->first();

        return view('portal.show', [
            'company' => $company,
            'employee' => $employee,
            'currentCompensation' => $currentCompensation,
            'latestContract' => $employee->contracts->first(),
            'payCycles' => [
                'monthly' => 'Monthly',
                'semi_monthly' => 'Semi-monthly',
                'bi_weekly' => 'Bi-weekly',
            ],
        ]);
    }

    public function payroll(Request $request): View
    {
        $company = $this->currentCompany($request);
        $employee = $this->currentEmployee($request);

        $this->authorize('view', $employee);

        $employee->load([
            'payrollEntries' => fn ($query) => $query
                ->with('payrollBatch')
                ->orderByDesc('due_date')
                ->orderByDesc('id'),
        ]);

        return view('employees.payroll', [
            'company' => $company,
            'employee' => $employee,
            'backUrl' => route('portal.show'),
            'backLabel' => 'Back to portal',
            'scopeLabel' => 'Self Service',
        ]);
    }
}
