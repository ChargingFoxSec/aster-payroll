<?php

namespace Tests;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\DemoCompany;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function demoCompany(): Company
    {
        return DemoCompany::resolve();
    }

    protected function createCompanyAdmin(?Company $company = null, array $attributes = []): User
    {
        $company ??= $this->demoCompany();

        return User::factory()
            ->companyAdmin($company)
            ->create($attributes);
    }

    protected function createEmployeeRecord(?Company $company = null, array $attributes = []): Employee
    {
        $company ??= $this->demoCompany();

        return Employee::query()->create(array_merge([
            'company_id' => $company->id,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'wallet_address' => null,
            'employment_status' => 'active',
            'start_date' => '2026-04-01',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ], $attributes));
    }

    protected function createEmployeeUser(?Employee $employee = null, array $attributes = []): User
    {
        $employee ??= $this->createEmployeeRecord();

        return User::factory()
            ->employee($employee)
            ->create($attributes);
    }

    protected function actingAsCompanyAdmin(?Company $company = null, array $attributes = []): User
    {
        $user = $this->createCompanyAdmin($company, $attributes);
        $this->actingAs($user);

        return $user;
    }

    protected function actingAsEmployeeUser(?Employee $employee = null, array $attributes = []): User
    {
        $user = $this->createEmployeeUser($employee, $attributes);
        $this->actingAs($user);

        return $user;
    }
}
