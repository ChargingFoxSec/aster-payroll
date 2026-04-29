<?php

namespace App\Http\Requests;

use App\Models\PayrollBatch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePayrollBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', PayrollBatch::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
            'due_date' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $period = $this->input('period');
            $dueDate = $this->input('due_date');

            if (! is_string($period) || ! is_string($dueDate) || preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
                return;
            }

            try {
                $periodStart = CarbonImmutable::parse("{$period}-01")->startOfDay();
                $periodEnd = $periodStart->endOfMonth();
                $normalizedDueDate = CarbonImmutable::parse($dueDate)->startOfDay();
            } catch (\Throwable) {
                return;
            }

            if ($normalizedDueDate->lt($periodStart) || $normalizedDueDate->gt($periodEnd)) {
                $validator->errors()->add('due_date', __('ui.messages.payroll_due_date_must_match_period'));
            }
        });
    }
}
