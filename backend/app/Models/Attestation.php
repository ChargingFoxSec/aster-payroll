<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'employee_id',
    'contract_id',
    'attestation_type',
    'external_id',
    'tx_signature',
    'payload_hash',
    'issued_at',
])]
class Attestation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
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
}
