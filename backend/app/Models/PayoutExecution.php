<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'employee_id',
    'payroll_entry_id',
    'approval_method',
    'status',
    'prepared_payload_path',
    'receipt_path',
    'approved_wallet_address',
    'tx_signature',
    'approved_at',
    'imported_at',
    'failure_reason',
])]
class PayoutExecution extends Model
{
    use HasFactory;

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    public const APPROVAL_METHOD_LOCAL_SIGNER = 'local_signer';

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'imported_at' => 'datetime',
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

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    public function isImported(): bool
    {
        return $this->status === self::STATUS_IMPORTED;
    }
}
