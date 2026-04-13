<?php

namespace App\Support;

use App\Models\Company;

class DemoCompany
{
    public static function resolve(): Company
    {
        return Company::query()->firstOrCreate(
            ['slug' => config('payroll.demo_company.slug')],
            [
                'name' => config('payroll.demo_company.name'),
                'wallet_address' => config('payroll.demo_company.wallet_address'),
            ],
        );
    }
}
