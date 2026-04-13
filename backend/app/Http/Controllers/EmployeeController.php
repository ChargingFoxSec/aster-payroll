<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\DemoCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(): View
    {
        $company = DemoCompany::resolve();

        return view('employees.index', [
            'company' => $company,
            'employees' => $company->employees()
                ->withCount('contracts')
                ->latest()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('employees.create', [
            'company' => DemoCompany::resolve(),
            'payCycles' => $this->payCycles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = DemoCompany::resolve();

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('employees', 'email')->where(
                    fn ($query) => $query->where('company_id', $company->id),
                ),
            ],
            'wallet_address' => ['nullable', 'string', 'max:64'],
            'employment_status' => ['required', Rule::in(['active', 'paused', 'terminated'])],
            'start_date' => ['nullable', 'date'],
            'pay_cycle' => ['required', Rule::in(array_keys($this->payCycles()))],
            'currency' => ['required', 'string', 'max:8'],
        ]);

        $employee = $company->employees()->create($validated);

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', 'Employee created. Upload a contract PDF to complete the first payroll record.');
    }

    public function show(Employee $employee): View
    {
        $company = DemoCompany::resolve();
        abort_unless($employee->company_id === $company->id, 404);

        $employee->load([
            'contracts' => fn ($query) => $query->latest('version'),
        ]);

        return view('employees.show', [
            'company' => $company,
            'employee' => $employee,
            'payCycles' => $this->payCycles(),
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
