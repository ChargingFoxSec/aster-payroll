<?php

namespace Tests\Fakes;

use App\Models\Company;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollBatch;
use App\Services\Solana\AnchorInstructionResult;
use App\Services\Solana\PayrollAnchorClient;
use Illuminate\Support\Collection;

class FakePayrollAnchorClient implements PayrollAnchorClient
{
    /** @var array<int, array{name:string,payload:array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    private int $contractCounter = 0;

    private int $amendmentCounter = 0;

    private int $batchCounter = 0;

    public function createEmploymentContract(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        string $currentCompensationRef,
    ): AnchorInstructionResult {
        $this->maybeFail('createEmploymentContract');
        $this->contractCounter++;
        $this->calls[] = [
            'name' => 'createEmploymentContract',
            'payload' => [
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'contract_id' => $contract->id,
                'current_compensation_ref' => $currentCompensationRef,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: $this->token('ContractPda', $this->contractCounter),
            txSignature: $this->token('ContractTx', $this->contractCounter, 64),
        );
    }

    public function amendCompensation(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        CompensationAmendment $amendment,
        string $amendmentHash,
    ): AnchorInstructionResult {
        $this->maybeFail('amendCompensation');
        $this->amendmentCounter++;
        $this->calls[] = [
            'name' => 'amendCompensation',
            'payload' => [
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'contract_id' => $contract->id,
                'compensation_amendment_id' => $amendment->id,
                'amendment_hash' => $amendmentHash,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: $this->token('AmendmentPda', $this->amendmentCounter),
            txSignature: $this->token('AmendmentTx', $this->amendmentCounter, 64),
        );
    }

    public function createPayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        Collection $entries,
        string $batchHash,
    ): AnchorInstructionResult {
        $this->maybeFail('createPayrollBatch');
        $this->batchCounter++;
        $this->calls[] = [
            'name' => 'createPayrollBatch',
            'payload' => [
                'company_id' => $company->id,
                'payroll_batch_id' => $payrollBatch->id,
                'entry_count' => $entries->count(),
                'batch_hash' => $batchHash,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: $this->token('BatchPda', $this->batchCounter),
            txSignature: $this->token('BatchTx', $this->batchCounter, 64),
        );
    }

    public function markPayrollBatchExecuted(
        Company $company,
        PayrollBatch $payrollBatch,
    ): AnchorInstructionResult {
        $this->maybeFail('markPayrollBatchExecuted');
        $this->calls[] = [
            'name' => 'markPayrollBatchExecuted',
            'payload' => [
                'company_id' => $company->id,
                'payroll_batch_id' => $payrollBatch->id,
                'anchor_batch_pubkey' => $payrollBatch->anchor_batch_pubkey,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: (string) $payrollBatch->anchor_batch_pubkey,
            txSignature: $this->token('BatchExecutedTx', $payrollBatch->id, 64),
        );
    }

    public function failOn(string $method, \Throwable $throwable): void
    {
        $this->failures[$method] = $throwable;
    }

    private function maybeFail(string $method): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }

    private function token(string $prefix, int $suffix, int $length = 44): string
    {
        return substr(str_pad($prefix.$suffix, $length, '1'), 0, $length);
    }
}
