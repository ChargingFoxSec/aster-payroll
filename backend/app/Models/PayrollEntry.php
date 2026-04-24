<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'payroll_batch_id',
    'employee_id',
    'compensation_amendment_id',
    'amount_minor',
    'currency',
    'status',
    'due_date',
    'paid_at',
    'tx_signature',
])]
class PayrollEntry extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function payrollBatch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function compensationAmendment(): BelongsTo
    {
        return $this->belongsTo(CompensationAmendment::class);
    }

    public function payoutExecution(): HasOne
    {
        return $this->hasOne(PayoutExecution::class);
    }
}
