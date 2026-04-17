<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Requests\ImportPayoutReceiptRequest;
use App\Http\Requests\PreparePayoutExecutionRequest;
use App\Models\PayoutExecution;
use App\Services\Payroll\ConfidentialPayrollService;
use App\Services\Payroll\PayrollReceiptImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PayrollDemoController extends Controller
{
    public function show(Request $request, ConfidentialPayrollService $confidentialPayrollService): View
    {
        $this->authorize('viewAny', PayoutExecution::class);

        $company = $this->currentCompany($request);
        $latestExecution = $confidentialPayrollService->latestExecution($company);

        return view('payroll.demo', [
            'company' => $company,
            'employees' => $company->employees()->orderBy('full_name')->get(),
            'latestExecution' => $latestExecution,
            'recentExecutions' => $company->payoutExecutions()
                ->with(['employee', 'payrollEntry.payrollBatch'])
                ->latest('updated_at')
                ->limit(5)
                ->get(),
            'defaultDueDate' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function prepare(
        PreparePayoutExecutionRequest $request,
        ConfidentialPayrollService $confidentialPayrollService,
    ): RedirectResponse {
        $company = $this->currentCompany($request);
        $validated = $request->validated();
        $employee = $company->employees()->findOrFail($validated['employee_id']);

        try {
            $execution = $confidentialPayrollService->prepareExecution(
                $company,
                $employee,
                $validated['due_date'],
            );
        } catch (UserFacingException $userFacingException) {
            return redirect()
                ->route('payroll-demo.show')
                ->withInput()
                ->with('error', $userFacingException->getMessage());
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()
                ->route('payroll-demo.show')
                ->withInput()
                ->with('error', 'Could not prepare the payout manifest. Check the application logs and try again.');
        }

        return redirect()
            ->route('payroll-demo.show')
            ->with('status', "Prepared payout execution #{$execution->id} for {$employee->full_name}. The next step is an admin-controlled local signer run, followed by receipt import.");
    }

    public function import(
        ImportPayoutReceiptRequest $request,
        ConfidentialPayrollService $confidentialPayrollService,
        PayrollReceiptImportService $payrollReceiptImportService,
    ): RedirectResponse {
        $company = $this->currentCompany($request);
        $validated = $request->validated();

        $execution = $company->payoutExecutions()
            ->with(['employee', 'payrollEntry.payrollBatch'])
            ->findOrFail($validated['payout_execution_id']);
        $this->authorize('import', $execution);

        try {
            $contents = $request->file('receipt')->get();

            if (! is_string($contents) || $contents === '') {
                throw new UserFacingException('Uploaded receipt could not be read.');
            }

            $receipt = $confidentialPayrollService->decodeJsonReceipt($contents);
            $receiptPath = $confidentialPayrollService->storeImportedReceipt($execution, $contents);
            $entry = $payrollReceiptImportService->importForExecution($execution, $receipt, $receiptPath);
        } catch (UserFacingException $userFacingException) {
            $this->markExecutionFailure($execution, $userFacingException->getMessage());

            return redirect()
                ->route('payroll-demo.show')
                ->withInput()
                ->with('error', $userFacingException->getMessage());
        } catch (Throwable $throwable) {
            report($throwable);

            $message = 'Receipt import failed. Check the application logs and try again.';
            $this->markExecutionFailure($execution, $message);

            return redirect()
                ->route('payroll-demo.show')
                ->withInput()
                ->with('error', $message);
        }

        return redirect()
            ->route('payroll-batches.show', $entry->payrollBatch)
            ->with('status', "Imported payout receipt for {$execution->employee->full_name}. Final approval is now attributed to {$execution->fresh()->approved_wallet_address}.");
    }

    public function downloadManifest(
        PayoutExecution $payoutExecution,
        ConfidentialPayrollService $confidentialPayrollService,
    ): StreamedResponse {
        $this->authorize('downloadManifest', $payoutExecution);

        abort_unless(
            $payoutExecution->prepared_payload_path !== null
            && Storage::disk('local')->exists($payoutExecution->prepared_payload_path),
            404,
        );

        return Storage::disk('local')->download(
            $payoutExecution->prepared_payload_path,
            $confidentialPayrollService->manifestDownloadName($payoutExecution),
            ['Content-Type' => 'application/json'],
        );
    }

    private function markExecutionFailure(PayoutExecution $execution, string $message): void
    {
        $execution->forceFill([
            'status' => PayoutExecution::STATUS_FAILED,
            'failure_reason' => $message,
        ])->save();
    }
}
