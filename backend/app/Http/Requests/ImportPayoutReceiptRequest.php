<?php

namespace App\Http\Requests;

use App\Models\PayoutExecution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportPayoutReceiptRequest extends FormRequest
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
            'payout_execution_id' => [
                'required',
                'integer',
                Rule::exists('payout_executions', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ],
            'receipt' => ['required', 'file', 'max:1024', 'mimetypes:application/json,text/plain,text/json'],
        ];
    }
}
