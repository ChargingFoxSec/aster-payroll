<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class PayrollBatchDraftService
{
    public function __construct(
        private readonly PayrollStatusService $payrollStatusService,
    ) {}

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

            if ($batch->exists && $batch->anchor_batch_pubkey !== null) {
                throw new UserFacingException(__('ui.messages.payroll_batch_committed_and_frozen'));
            }

            $batch->fill([
                'currency' => $this->supportedCurrency(),
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
                $this->assertSupportedCurrency(
                    $employee->currency,
                    __('ui.messages.employee_currency_unsupported_for_batch_draft', ['currency' => $this->supportedCurrency()]),
                );

                $amendment = $employee->effectiveCompensationAt($dueDate);

                if (! $amendment) {
                    continue;
                }

                $this->assertSupportedCurrency(
                    $amendment->currency,
                    __('ui.messages.compensation_currency_unsupported_for_batch_draft', ['currency' => $this->supportedCurrency()]),
                );

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
                    'currency' => $this->supportedCurrency(),
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

            $this->payrollStatusService->syncLoadedBatch(
                $batch,
                $batch->entries()->orderBy('id')->get(),
                $dueDate,
            );

            $batch = $batch->fresh([
                'entries' => fn ($query) => $query
                    ->with(['employee', 'compensationAmendment'])
                    ->orderBy('id'),
            ]);

            if (! $batch || $batch->entries->isEmpty()) {
                throw new UserFacingException(__('ui.messages.no_active_employee_compensation_for_batch'));
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

    private function supportedCurrency(): string
    {
        return (string) config('payroll.currency.code', 'USDC');
    }

    private function assertSupportedCurrency(string $currency, string $message): void
    {
        if ($currency !== $this->supportedCurrency()) {
            throw new UserFacingException($message);
        }
    }
}
