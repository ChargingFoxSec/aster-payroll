<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeePayrollController extends Controller
{
    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $company = $this->currentCompany($request);

        $employee->load([
            'payrollEntries' => fn ($query) => $query
                ->with('payrollBatch')
                ->orderByDesc('due_date')
                ->orderByDesc('id'),
        ]);

        return view('employees.payroll', [
            'company' => $company,
            'employee' => $employee,
            'backUrl' => route('employees.show', $employee),
            'backLabel' => __('ui.actions.back_to_employee'),
            'scopeLabel' => __('ui.pages.employees.detail_kicker'),
        ]);
    }
}
