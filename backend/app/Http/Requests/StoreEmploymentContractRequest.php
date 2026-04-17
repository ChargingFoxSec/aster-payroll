<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmploymentContractRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['draft', 'active', 'superseded'])],
            'contract_pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ];
    }
}
