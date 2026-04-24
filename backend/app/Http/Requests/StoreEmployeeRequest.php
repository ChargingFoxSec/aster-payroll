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
        $supportedCurrency = (string) config('payroll.currency.code', 'USDC');

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => array_values(array_filter([
                'required',
                'email',
                'max:255',
                Rule::unique('employees', 'email')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
                $this->boolean('provision_portal_account')
                    ? Rule::unique('users', 'email')
                    : null,
            ])),
            'wallet_address' => ['nullable', 'string', 'max:64'],
            'employment_status' => ['required', Rule::in(['active', 'paused', 'terminated'])],
            'start_date' => ['nullable', 'date'],
            'pay_cycle' => ['required', Rule::in(['monthly', 'semi_monthly', 'bi_weekly'])],
            'currency' => ['required', Rule::in([$supportedCurrency])],
            'provision_portal_account' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'wallet_address' => $this->normalizeOptionalString('wallet_address'),
            'currency' => strtoupper(trim((string) $this->input('currency'))),
            'provision_portal_account' => $this->boolean('provision_portal_account'),
        ]);
    }

    private function normalizeOptionalString(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
