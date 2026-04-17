<?php

namespace App\Policies;

use App\Models\PayoutExecution;
use App\Models\User;

class PayoutExecutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyAdmin() && $user->company_id !== null;
    }

    public function view(User $user, PayoutExecution $payoutExecution): bool
    {
        return $user->isCompanyAdmin() && $user->belongsToCompany($payoutExecution->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isCompanyAdmin() && $user->company_id !== null;
    }

    public function import(User $user, PayoutExecution $payoutExecution): bool
    {
        return $user->isCompanyAdmin()
            && $user->belongsToCompany($payoutExecution->company_id)
            && ! $payoutExecution->isImported();
    }

    public function downloadManifest(User $user, PayoutExecution $payoutExecution): bool
    {
        return $user->isCompanyAdmin() && $user->belongsToCompany($payoutExecution->company_id);
    }
}
