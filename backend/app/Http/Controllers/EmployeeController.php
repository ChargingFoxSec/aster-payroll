<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);

        $company = $this->currentCompany($request);

        return view('employees.index', [
            'company' => $company,
            'employees' => $company->employees()
                ->withCount('contracts')
                ->latest()
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Employee::class);

        return view('employees.create', [
            'company' => $this->currentCompany($request),
            'payCycles' => $this->payCycles(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $company = $this->currentCompany($request);
        $employee = $company->employees()->create($request->validated());

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee created. Upload a contract PDF to complete the first payroll record.');
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $company = $this->currentCompany($request);

        $employee->load([
            'contracts' => fn ($query) => $query->latest('version'),
            'compensationAmendments' => fn ($query) => $query
                ->with('contract')
                ->orderByDesc('effective_date')
                ->orderByDesc('id'),
            'payrollEntries' => fn ($query) => $query
                ->with('payrollBatch')
                ->orderByDesc('due_date')
                ->orderByDesc('id')
                ->limit(5),
        ]);

        $currentCompensation = $employee->compensationAmendments
            ->first(fn ($amendment) => $amendment->effective_date->lte(now()->startOfDay()))
            ?? $employee->compensationAmendments->first();

        return view('employees.show', [
            'company' => $company,
            'employee' => $employee,
            'payCycles' => $this->payCycles(),
            'currentCompensation' => $currentCompensation,
            'latestContract' => $employee->contracts->first(),
        ]);
    }

    private function payCycles(): array
    {
        return [
            'monthly' => 'Monthly',
            'semi_monthly' => 'Semi-monthly',
            'bi_weekly' => 'Bi-weekly',
        ];
    }
}
