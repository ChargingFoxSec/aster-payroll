<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\StoreEmploymentContractRequest;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Services\Contracts\ContractUploadService;
use App\Services\Solana\PayrollAnchoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContractController extends Controller
{
    public function store(
        StoreEmploymentContractRequest $request,
        Employee $employee,
        ContractUploadService $contractUploadService,
        PayrollAnchoringService $payrollAnchoringService,
    ): RedirectResponse {
        $this->authorize('manage', $employee);

        $company = $this->currentCompany($request);
        $contractPdf = $request->file('contract_pdf');

        if (! $contractPdf instanceof UploadedFile) {
            return redirect()
                ->route('employees.show', $employee)
                ->withInput()
                ->with('error', __('ui.messages.contract_pdf_required'));
        }

        try {
            $contract = $contractUploadService->upload(
                $company,
                $employee,
                $contractPdf,
                $request->validated(),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('employees.show', $employee)
                ->withInput()
                ->with('error', __('ui.messages.contract_store_failed'));
        }

        $anchorWarning = $this->syncContractWarning($payrollAnchoringService, $contract);

        return redirect()
            ->route('employees.show', $employee)
            ->with(
                'status',
                trim(implode(' ', array_filter([
                    __('ui.messages.contract_uploaded', ['version' => $contract->version]),
                    $anchorWarning,
                ]))),
            );
    }

    public function download(Request $request, EmploymentContract $contract): StreamedResponse
    {
        $this->authorize('download', $contract);

        return Storage::disk('local')->download(
            $contract->file_path,
            basename($contract->file_path),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function syncContractWarning(
        PayrollAnchoringService $payrollAnchoringService,
        EmploymentContract $contract,
    ): ?string {
        try {
            return $payrollAnchoringService->syncContract($contract);
        } catch (UserFacingException $userFacingException) {
            return $userFacingException->getMessage();
        } catch (Throwable $throwable) {
            report($throwable);

            return __('ui.messages.contract_anchor_failed');
        }
    }
}
