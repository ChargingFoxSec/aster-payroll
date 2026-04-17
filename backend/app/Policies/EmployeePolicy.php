<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyAdmin() && $user->company_id !== null;
    }

    public function view(User $user, Employee $employee): bool
    {
        if (! $user->belongsToCompany($employee->company_id)) {
            return false;
        }

        return $user->isCompanyAdmin() || $user->employee_id === $employee->id;
    }

    public function create(User $user): bool
    {
        return $user->isCompanyAdmin() && $user->company_id !== null;
    }

    public function manage(User $user, Employee $employee): bool
    {
        return $user->isCompanyAdmin() && $user->belongsToCompany($employee->company_id);
    }
}
