<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id',
    'employee_id',
    'version',
    'file_path',
    'file_hash',
    'title',
    'effective_date',
    'status',
    'anchor_contract_pubkey',
])]
class EmploymentContract extends Model
{
    use HasFactory;

    protected $table = 'contracts';

    protected function casts(): array
    {
        return [
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

    public function compensationAmendments(): HasMany
    {
        return $this->hasMany(CompensationAmendment::class, 'contract_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class, 'contract_id');
    }

    public function latestAttestation(): HasOne
    {
        return $this->hasOne(Attestation::class, 'contract_id')->latestOfMany();
    }
}
