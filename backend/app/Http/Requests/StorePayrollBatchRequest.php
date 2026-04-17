<?php

namespace App\Http\Requests;

use App\Models\PayrollBatch;
use Illuminate\Foundation\Http\FormRequest;

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
}
