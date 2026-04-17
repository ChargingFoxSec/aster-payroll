<?php

namespace App\Policies;

use App\Models\EmploymentContract;
use App\Models\User;

class EmploymentContractPolicy
{
    public function download(User $user, EmploymentContract $contract): bool
    {
        return $user->isCompanyAdmin() && $user->belongsToCompany($contract->company_id);
    }
}
