<?php

namespace App\Http\Controllers;

use App\Services\Payroll\ConfidentialPayrollService;
use Throwable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PayrollDemoController extends Controller
{
    public function show(ConfidentialPayrollService $confidentialPayrollService): View
    {
        return view('payroll.demo', [
            'latestReceipt' => $confidentialPayrollService->latestReceipt(),
            'receiptPath' => $confidentialPayrollService->receiptPath(),
            'rpcUrl' => config('payroll.confidential.rpc_url'),
        ]);
    }

    public function run(ConfidentialPayrollService $confidentialPayrollService): RedirectResponse
    {
        try {
            $receipt = $confidentialPayrollService->runDemo();
        } catch (Throwable $throwable) {
            return redirect()
                ->route('payroll-demo.show')
                ->with('error', $throwable->getMessage());
        }

        $transactionCount = count(array_filter($receipt['transactions'] ?? []));

        return redirect()
            ->route('payroll-demo.show')
            ->with('status', "Confidential payroll PoC finished with {$transactionCount} tracked transactions.");
    }
}
