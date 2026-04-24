<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Employee;
use App\Services\Employees\EmployeeOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

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

    public function store(
        StoreEmployeeRequest $request,
        EmployeeOnboardingService $employeeOnboardingService,
    ): RedirectResponse
    {
        $company = $this->currentCompany($request);

        try {
            $result = $employeeOnboardingService->create($company, $request->validated());
        } catch (ValidationException $validationException) {
            throw $validationException;
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('employees.create')
                ->withInput()
                ->with('error', __('ui.messages.employee_create_failed'));
        }

        $status = $result->portalProvisioned()
            ? __('ui.messages.employee_created_with_portal')
            : __('ui.messages.employee_created');

        $response = redirect()
            ->route('employees.show', $result->employee)
            ->with('status', $status);

        if ($result->portalProvisioned()) {
            $response->with('provisioned_portal_account', [
                'employee_name' => $result->employee->full_name,
                'email' => $result->portalUser?->email,
                'temporary_password' => $result->temporaryPassword,
            ]);
        }

        return $response;
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $company = $this->currentCompany($request);

        $employee->load([
            'user',
            'contracts' => fn ($query) => $query
                ->with('latestAttestation')
                ->latest('version'),
            'compensationAmendments' => fn ($query) => $query
                ->with(['contract', 'latestAttestation'])
                ->orderByDesc('effective_date')
                ->orderByDesc('id'),
            'payrollEntries' => fn ($query) => $query
                ->with('payrollBatch')
                ->orderByDesc('due_date')
                ->orderByDesc('id')
                ->limit(5),
        ]);

        $currentCompensation = $employee->currentCompensation();

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
