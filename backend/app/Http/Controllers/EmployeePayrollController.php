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
            'backLabel' => 'Back to employee',
            'scopeLabel' => 'Employee Scope',
        ]);
    }
}
