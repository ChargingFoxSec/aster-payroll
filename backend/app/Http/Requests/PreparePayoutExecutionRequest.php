<?php

namespace App\Http\Requests;

use App\Models\PayoutExecution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreparePayoutExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', PayoutExecution::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ],
            'due_date' => ['required', 'date'],
        ];
    }
}
