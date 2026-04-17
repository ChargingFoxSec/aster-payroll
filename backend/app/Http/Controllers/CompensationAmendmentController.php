<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\StoreCompensationAmendmentRequest;
use App\Models\Employee;
use App\Services\Payroll\CompensationAmountParser;
use Illuminate\Http\RedirectResponse;
use Throwable;

class CompensationAmendmentController extends Controller
{
    public function store(
        StoreCompensationAmendmentRequest $request,
        Employee $employee,
        CompensationAmountParser $compensationAmountParser,
    ): RedirectResponse {
        $this->authorize('manage', $employee);

        $company = $this->currentCompany($request);

        $contract = $employee->contracts()->latest('version')->first();

        if (! $contract) {
            return redirect()
                ->route('employees.show', $employee)
                ->with('error', 'Upload a contract before recording compensation.');
        }

        try {
            $validated = $request->validated();
            $previousAmendment = $employee->compensationAmendments()
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();

            $employee->compensationAmendments()->create([
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'previous_amount_minor' => $previousAmendment?->new_amount_minor,
                'new_amount_minor' => $compensationAmountParser->parseMinor($validated['new_amount']),
                'currency' => $employee->currency,
                'effective_date' => $validated['effective_date'],
                'reason' => $validated['reason'],
            ]);
        } catch (UserFacingException $userFacingException) {
            return redirect()
                ->route('employees.show', $employee)
                ->withInput()
                ->with('error', $userFacingException->getMessage());
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('employees.show', $employee)
                ->withInput()
                ->with('error', 'The compensation record could not be saved right now. Check the application logs and try again.');
        }

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', "Compensation update recorded for {$employee->full_name}.");
    }
}
