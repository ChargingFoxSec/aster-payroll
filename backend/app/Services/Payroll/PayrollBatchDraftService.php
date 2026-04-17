<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class PayrollBatchDraftService
{
    public function createOrRefresh(
        Company $company,
        string $period,
        DateTimeInterface|string $dueDate,
    ): PayrollBatch {
        $periodDate = CarbonImmutable::parse("{$period}-01")->startOfMonth();
        $dueDate = $this->normalizeDate($dueDate);

        return DB::transaction(function () use ($company, $periodDate, $dueDate): PayrollBatch {
            $batch = PayrollBatch::query()->firstOrNew([
                'company_id' => $company->id,
                'period_year' => $periodDate->year,
                'period_month' => $periodDate->month,
            ]);

            $batch->fill([
                'currency' => $company->employees()->value('currency') ?? 'USDC',
                'due_date' => $dueDate->toDateString(),
            ]);
            $batch->status ??= 'draft';
            $batch->save();

            $eligibleEmployeeIds = [];

            $employees = $company->employees()
                ->where('employment_status', 'active')
                ->orderBy('full_name')
                ->get();

            foreach ($employees as $employee) {
                $amendment = $this->resolveEffectiveCompensation($employee, $dueDate);

                if (! $amendment) {
                    continue;
                }

                $eligibleEmployeeIds[] = $employee->id;

                $entry = PayrollEntry::query()->firstOrNew([
                    'payroll_batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                ]);

                if ($entry->paid_at !== null) {
                    continue;
                }

                $entry->fill([
                    'compensation_amendment_id' => $amendment->id,
                    'amount_minor' => $amendment->new_amount_minor,
                    'currency' => $amendment->currency,
                    'status' => 'draft',
                    'due_date' => $dueDate->toDateString(),
                    'paid_at' => null,
                    'tx_signature' => null,
                ]);
                $entry->save();
            }

            $staleDraftEntries = $batch->entries()
                ->whereNull('paid_at')
                ->whereNull('tx_signature');

            if ($eligibleEmployeeIds !== []) {
                $staleDraftEntries->whereNotIn('employee_id', $eligibleEmployeeIds);
            }

            $staleDraftEntries->delete();

            $batch->forceFill([
                'total_amount_minor' => (int) $batch->entries()->sum('amount_minor'),
                'status' => $this->resolveBatchStatus($batch),
            ])->save();

            $batch = $batch->fresh([
                'entries' => fn ($query) => $query
                    ->with(['employee', 'compensationAmendment'])
                    ->orderBy('id'),
            ]);

            if (! $batch || $batch->entries->isEmpty()) {
                throw new UserFacingException('No active employees have an effective compensation record for this payroll batch yet.');
            }

            return $batch;
        });
    }

    private function normalizeDate(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value->format(DATE_ATOM))->startOfDay();
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function resolveBatchStatus(PayrollBatch $batch): string
    {
        $entries = $batch->entries()->get(['paid_at']);

        if ($entries->isEmpty()) {
            return 'draft';
        }

        if ($entries->every(fn (PayrollEntry $entry) => $entry->paid_at !== null)) {
            return 'executed';
        }

        if ($entries->contains(fn (PayrollEntry $entry) => $entry->paid_at !== null)) {
            return 'partially_paid';
        }

        return 'draft';
    }

    private function resolveEffectiveCompensation(Employee $employee, CarbonImmutable $dueDate): ?CompensationAmendment
    {
        return $employee->compensationAmendments()
            ->whereDate('effective_date', '<=', $dueDate->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }
}
