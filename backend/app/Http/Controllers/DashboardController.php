<?php

namespace App\Http\Controllers;

use App\Services\Payroll\ConfidentialPayrollService;
use App\Support\DemoCompany;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ConfidentialPayrollService $confidentialPayrollService): View
    {
        $company = DemoCompany::resolve()->loadCount(['employees', 'contracts', 'payrollBatches']);

        return view('dashboard.index', [
            'company' => $company,
            'latestReceipt' => $confidentialPayrollService->latestReceipt(),
        ]);
    }
}
