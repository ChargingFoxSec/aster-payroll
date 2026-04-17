<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'wallet_address'])]
class Company extends Model
{
    use HasFactory;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    public function compensationAmendments(): HasMany
    {
        return $this->hasMany(CompensationAmendment::class);
    }

    public function payrollBatches(): HasMany
    {
        return $this->hasMany(PayrollBatch::class);
    }

    public function payoutExecutions(): HasMany
    {
        return $this->hasMany(PayoutExecution::class);
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }
}
