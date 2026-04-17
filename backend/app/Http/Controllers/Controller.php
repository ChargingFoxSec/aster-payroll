<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Support\DemoCompany;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    protected function currentCompany(Request $request): Company
    {
        return $request->user()?->company ?? DemoCompany::resolve();
    }

    protected function currentEmployee(Request $request): Employee
    {
        $user = $request->user();
        $employee = $user?->employee;

        abort_unless(
            $user !== null
            && $employee !== null
            && $user->belongsToCompany($employee->company_id),
            403,
        );

        return $employee;
    }
}
