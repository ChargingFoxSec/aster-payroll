<?php

namespace App\Services\Employees;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeOnboardingService
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function create(Company $company, array $attributes): EmployeeOnboardingResult
    {
        return DB::transaction(function () use ($company, $attributes): EmployeeOnboardingResult {
            $provisionPortalAccount = (bool) ($attributes['provision_portal_account'] ?? false);
            unset($attributes['provision_portal_account']);

            $employee = $company->employees()->create($attributes);

            if (! $provisionPortalAccount) {
                return new EmployeeOnboardingResult(
                    employee: $employee->fresh('user'),
                );
            }

            if (User::query()->where('email', $employee->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('ui.messages.employee_portal_email_in_use'),
                ]);
            }

            $temporaryPassword = Str::password(16, true, true, false, false);
            $portalUser = User::query()->create([
                'name' => $employee->full_name,
                'email' => $employee->email,
                'email_verified_at' => now(),
                'password' => $temporaryPassword,
                'role' => User::ROLE_EMPLOYEE,
                'company_id' => $company->id,
                'employee_id' => $employee->id,
            ]);

            return new EmployeeOnboardingResult(
                employee: $employee->fresh('user'),
                portalUser: $portalUser,
                temporaryPassword: $temporaryPassword,
            );
        });
    }
}
