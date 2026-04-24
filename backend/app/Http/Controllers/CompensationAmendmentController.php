<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\StoreCompensationAmendmentRequest;
use App\Models\Employee;
use App\Services\Payroll\CompensationAmountParser;
use App\Services\Solana\PayrollAnchoringService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class CompensationAmendmentController extends Controller
{
    public function store(
        StoreCompensationAmendmentRequest $request,
        Employee $employee,
        CompensationAmountParser $compensationAmountParser,
        PayrollAnchoringService $payrollAnchoringService,
    ): RedirectResponse {
        $this->authorize('manage', $employee);

        $company = $this->currentCompany($request);

        $contract = $employee->contracts()->latest('version')->first();

        if (! $contract) {
            return redirect()
                ->route('employees.show', $employee)
                ->with('error', __('ui.messages.compensation_contract_required'));
        }

        try {
            $validated = $request->validated();
            $previousAmendment = $employee->compensationAmendments()
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();

            $amendment = $employee->compensationAmendments()->create([
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'previous_amount_minor' => $previousAmendment?->new_amount_minor,
                'new_amount_minor' => $compensationAmountParser->parseMinor($validated['new_amount']),
                'currency' => $employee->currency,
                'effective_date' => $validated['effective_date'],
                'reason' => $validated['reason'],
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('employees.show', $employee)
                ->withInput()
                ->with('error', __('ui.messages.compensation_store_failed'));
        }

        $anchorWarning = $this->syncCompensationWarning($payrollAnchoringService, $amendment);

        return redirect()
            ->route('employees.show', $employee)
            ->with(
                'status',
                trim(implode(' ', array_filter([
                    __('ui.messages.compensation_recorded', ['employee' => $employee->full_name]),
                    $anchorWarning,
                ]))),
            );
    }

    private function syncCompensationWarning(
        PayrollAnchoringService $payrollAnchoringService,
        \App\Models\CompensationAmendment $amendment,
    ): ?string {
        try {
            return $payrollAnchoringService->syncCompensationAmendment($amendment);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        } catch (Throwable $throwable) {
            report($throwable);

            return __('ui.messages.compensation_anchor_failed');
        }
    }
}
