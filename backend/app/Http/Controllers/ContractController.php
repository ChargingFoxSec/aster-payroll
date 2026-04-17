<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\StoreEmploymentContractRequest;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Services\Contracts\ContractUploadService;
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
    ): RedirectResponse {
        $this->authorize('manage', $employee);

        $company = $this->currentCompany($request);
        $contractPdf = $request->file('contract_pdf');

        if (! $contractPdf instanceof UploadedFile) {
            return redirect()
                ->route('employees.show', $employee)
                ->withInput()
                ->with('error', 'Upload a PDF contract before submitting.');
        }

        try {
            $contract = $contractUploadService->upload(
                $company,
                $employee,
                $contractPdf,
                $request->validated(),
            );
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
                ->with('error', 'The contract could not be stored right now. Check the application logs and try again.');
        }

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', "Contract v{$contract->version} uploaded and hashed.");
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
}
