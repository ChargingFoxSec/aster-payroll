<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'full_name',
    'email',
    'wallet_address',
    'employment_status',
    'start_date',
    'pay_cycle',
    'currency',
])]
class Employee extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    public function compensationAmendments(): HasMany
    {
        return $this->hasMany(CompensationAmendment::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }
}
