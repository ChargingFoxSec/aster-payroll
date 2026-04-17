<?php

namespace App\Policies;

use App\Models\PayrollBatch;
use App\Models\User;

class PayrollBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyAdmin() && $user->company_id !== null;
    }

    public function view(User $user, PayrollBatch $payrollBatch): bool
    {
        return $user->isCompanyAdmin() && $user->belongsToCompany($payrollBatch->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isCompanyAdmin() && $user->company_id !== null;
    }
}
