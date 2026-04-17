<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompensationAmendmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && (bool) $this->user()?->can('manage', $employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = trim((string) $this->input('reason'));

        $this->merge([
            'new_amount' => trim((string) $this->input('new_amount')),
            'reason' => $reason === '' ? null : $reason,
        ]);
    }
}
