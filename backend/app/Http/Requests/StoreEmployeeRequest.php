<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Employee::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('employees', 'email')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ],
            'wallet_address' => ['nullable', 'string', 'max:64'],
            'employment_status' => ['required', Rule::in(['active', 'paused', 'terminated'])],
            'start_date' => ['nullable', 'date'],
            'pay_cycle' => ['required', Rule::in(['monthly', 'semi_monthly', 'bi_weekly'])],
            'currency' => ['required', 'string', 'max:8'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'wallet_address' => $this->normalizeOptionalString('wallet_address'),
            'currency' => strtoupper(trim((string) $this->input('currency'))),
        ]);
    }

    private function normalizeOptionalString(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
