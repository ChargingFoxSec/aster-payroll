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
    'period_year',
    'period_month',
    'status',
    'total_amount_minor',
    'currency',
    'due_date',
    'entry_count',
    'entries_root',
    'approval_root',
    'settlement_root',
    'approved_by',
    'approved_at',
    'finalized_by',
    'executed_at',
    'anchor_batch_pubkey',
])]
class PayrollBatch extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_OVERDUE = 'overdue';

    protected function casts(): array
    {
        return [
            'total_amount_minor' => 'integer',
            'due_date' => 'date',
            'entry_count' => 'integer',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function entryProofs(): HasMany
    {
        return $this->hasMany(PayrollEntryProof::class);
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }

    public function latestAttestation(): HasOne
    {
        return $this->hasOne(Attestation::class)->latestOfMany();
    }

    public function latestCommitAttestation(): HasOne
    {
        return $this->hasOne(Attestation::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('attestation_type', 'payroll_batch_commit'),
        );
    }

    public function latestApprovalAttestation(): HasOne
    {
        return $this->hasOne(Attestation::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('attestation_type', 'payroll_batch_approval'),
        );
    }

    public function latestFinalizationAttestation(): HasOne
    {
        return $this->hasOne(Attestation::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('attestation_type', 'payroll_batch_finalization'),
        );
    }

    public function latestAnchorAttestation(): HasOne
    {
        return $this->latestCommitAttestation();
    }

    public function latestExecutionAttestation(): HasOne
    {
        return $this->latestFinalizationAttestation();
    }
}
