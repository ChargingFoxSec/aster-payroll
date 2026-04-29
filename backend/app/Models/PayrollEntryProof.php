<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payroll_batch_id',
    'payroll_entry_id',
    'position',
    'proof_version',
    'employee_ref_hash',
    'compensation_ref_hash',
    'amount_commitment_hash',
    'amount_nonce',
    'leaf_hash',
    'leaf_payload',
    'proof_path',
])]
class PayrollEntryProof extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'leaf_payload' => 'array',
            'proof_path' => 'array',
        ];
    }

    public function payrollBatch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class);
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }
}
