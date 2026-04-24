<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

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

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    public function compensationAmendments(): HasMany
    {
        return $this->hasMany(CompensationAmendment::class);
    }

    public function currentCompensation(
        DateTimeInterface|string|CarbonInterface|null $asOf = null,
    ): ?CompensationAmendment {
        $referenceDate = $this->normalizeCompensationDate($asOf);
        $timeline = $this->compensationTimeline();

        return $timeline->first(
            fn (CompensationAmendment $amendment): bool => $amendment->effective_date !== null
                && $amendment->effective_date->lte($referenceDate),
        ) ?? $timeline->first();
    }

    public function effectiveCompensationAt(
        DateTimeInterface|string|CarbonInterface $asOf,
    ): ?CompensationAmendment {
        $referenceDate = $this->normalizeCompensationDate($asOf);

        return $this->compensationTimeline()->first(
            fn (CompensationAmendment $amendment): bool => $amendment->effective_date !== null
                && $amendment->effective_date->lte($referenceDate),
        );
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function payoutExecutions(): HasMany
    {
        return $this->hasMany(PayoutExecution::class);
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(Attestation::class);
    }

    /**
     * @return Collection<int, CompensationAmendment>
     */
    private function compensationTimeline(): Collection
    {
        if ($this->relationLoaded('compensationAmendments')) {
            return $this->compensationAmendments
                ->sortByDesc(
                    fn (CompensationAmendment $amendment): string => sprintf(
                        '%s-%020d',
                        $amendment->effective_date?->toDateString() ?? '',
                        $amendment->id,
                    ),
                )
                ->values();
        }

        return $this->compensationAmendments()
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();
    }

    private function normalizeCompensationDate(
        DateTimeInterface|string|CarbonInterface|null $value,
    ): CarbonImmutable {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->toIso8601String())->startOfDay();
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format(DATE_ATOM))->startOfDay();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->startOfDay();
        }

        return CarbonImmutable::now()->startOfDay();
    }
}
