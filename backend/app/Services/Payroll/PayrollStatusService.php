<?php

namespace App\Services\Payroll;

use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollStatusService
{
    public function syncBatch(
        PayrollBatch $batch,
        DateTimeInterface|string|CarbonInterface|null $asOf = null,
    ): PayrollBatch {
        return DB::transaction(function () use ($batch, $asOf): PayrollBatch {
            $lockedBatch = PayrollBatch::query()
                ->lockForUpdate()
                ->findOrFail($batch->id);
            $entries = $lockedBatch->entries()
                ->lockForUpdate()
                ->get();

            $this->syncLoadedBatch($lockedBatch, $entries, $asOf);

            return $lockedBatch->fresh(['entries']);
        });
    }

    /**
     * @param  Collection<int, PayrollEntry>  $entries
     */
    public function syncLoadedBatch(
        PayrollBatch $batch,
        Collection $entries,
        DateTimeInterface|string|CarbonInterface|null $asOf = null,
    ): PayrollBatch {
        $today = $this->normalizeDate($asOf);

        $entries->each(function (PayrollEntry $entry) use ($today): void {
            $resolvedStatus = $this->resolveEntryStatus($entry, $today);

            if ($entry->status !== $resolvedStatus) {
                $entry->forceFill([
                    'status' => $resolvedStatus,
                ])->save();
            }
        });

        $paidAtValues = $entries
            ->pluck('paid_at')
            ->filter();
        $allEntriesPaid = $entries->isNotEmpty()
            && $entries->every(fn (PayrollEntry $entry) => $entry->paid_at !== null);

        $batch->forceFill([
            'total_amount_minor' => (int) $entries->sum('amount_minor'),
            'status' => $this->resolveBatchStatus($entries, $today),
            'executed_at' => $allEntriesPaid
                ? ($batch->executed_at ?? $paidAtValues->max())
                : null,
        ])->save();

        return $batch;
    }

    public function syncOverdueBatches(
        DateTimeInterface|string|CarbonInterface|null $asOf = null,
    ): int {
        $today = $this->normalizeDate($asOf);
        $batchIds = PayrollBatch::query()
            ->whereHas('entries', function ($query) use ($today): void {
                $query
                    ->whereNull('paid_at')
                    ->whereDate('due_date', '<', $today->toDateString());
            })
            ->pluck('id');

        foreach ($batchIds as $batchId) {
            $this->syncBatch(
                PayrollBatch::query()->findOrFail($batchId),
                $today,
            );
        }

        return $batchIds->count();
    }

    public function resolveEntryStatus(
        PayrollEntry $entry,
        DateTimeInterface|string|CarbonInterface|null $asOf = null,
    ): string {
        $today = $this->normalizeDate($asOf);

        if ($entry->paid_at !== null) {
            return PayrollEntry::STATUS_PAID;
        }

        if ($entry->due_date !== null && $entry->due_date->lt($today)) {
            return PayrollEntry::STATUS_OVERDUE;
        }

        return in_array($entry->status, [
            PayrollEntry::STATUS_DRAFT,
            PayrollEntry::STATUS_PENDING,
        ], true)
            ? $entry->status
            : PayrollEntry::STATUS_PENDING;
    }

    /**
     * @param  Collection<int, PayrollEntry>  $entries
     */
    public function resolveBatchStatus(
        Collection $entries,
        DateTimeInterface|string|CarbonInterface|null $asOf = null,
    ): string {
        $today = $this->normalizeDate($asOf);

        if ($entries->isEmpty()) {
            return PayrollBatch::STATUS_DRAFT;
        }

        $resolvedEntryStatuses = $entries
            ->map(fn (PayrollEntry $entry): string => $this->resolveEntryStatus($entry, $today));

        if ($entries->every(fn (PayrollEntry $entry) => $entry->paid_at !== null)) {
            return PayrollBatch::STATUS_EXECUTED;
        }

        if ($resolvedEntryStatuses->contains(PayrollEntry::STATUS_OVERDUE)) {
            return PayrollBatch::STATUS_OVERDUE;
        }

        if ($entries->contains(fn (PayrollEntry $entry) => $entry->paid_at !== null)) {
            return PayrollBatch::STATUS_PARTIALLY_PAID;
        }

        if ($resolvedEntryStatuses->contains(PayrollEntry::STATUS_PENDING)) {
            return PayrollBatch::STATUS_PENDING;
        }

        return PayrollBatch::STATUS_DRAFT;
    }

    private function normalizeDate(
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
