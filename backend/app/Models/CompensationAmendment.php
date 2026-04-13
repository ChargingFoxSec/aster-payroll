<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'employee_id',
    'contract_id',
    'previous_amount_minor',
    'new_amount_minor',
    'currency',
    'effective_date',
    'reason',
    'anchor_amendment_pubkey',
])]
class CompensationAmendment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'previous_amount_minor' => 'integer',
            'new_amount_minor' => 'integer',
            'effective_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'contract_id');
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }
}
