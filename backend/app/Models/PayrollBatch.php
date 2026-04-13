<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'period_year',
    'period_month',
    'status',
    'total_amount_minor',
    'currency',
    'due_date',
    'executed_at',
    'anchor_batch_pubkey',
])]
class PayrollBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'total_amount_minor' => 'integer',
            'due_date' => 'date',
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
}
