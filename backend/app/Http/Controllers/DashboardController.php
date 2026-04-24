<?php

namespace App\Http\Controllers;

use App\Services\Payroll\ConfidentialPayrollService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ConfidentialPayrollService $confidentialPayrollService): View
    {
        $company = $this->currentCompany($request)->loadCount([
            'employees',
            'contracts',
            'payrollBatches',
            'employees as active_employees_count' => fn ($query) => $query->where('employment_status', 'active'),
        ]);
        $latestExecution = $confidentialPayrollService->latestExecution($company);

        return view('dashboard.index', [
            'company' => $company,
            'latestExecution' => $latestExecution,
            'latestReceipt' => $confidentialPayrollService->latestReceipt($company),
        ]);
    }
}
