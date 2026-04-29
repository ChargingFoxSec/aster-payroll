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

    public function commitPayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        Collection $entries,
        string $entriesRoot,
        int $entryCount,
    ): AnchorInstructionResult {
        $this->maybeFail('commitPayrollBatch');
        $this->batchCounter++;
        $this->calls[] = [
            'name' => 'commitPayrollBatch',
            'payload' => [
                'company_id' => $company->id,
                'payroll_batch_id' => $payrollBatch->id,
                'entry_count' => $entryCount,
                'entries_count' => $entries->count(),
                'entries_root' => $entriesRoot,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: $this->token('BatchPda', $this->batchCounter),
            txSignature: $this->token('BatchTx', $this->batchCounter, 64),
            authorityPubkey: $this->token('AuthorityPda', $company->id),
        );
    }

    public function approvePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $approvalRoot,
    ): AnchorInstructionResult {
        $this->maybeFail('approvePayrollBatch');
        $this->calls[] = [
            'name' => 'approvePayrollBatch',
            'payload' => [
                'company_id' => $company->id,
                'payroll_batch_id' => $payrollBatch->id,
                'anchor_batch_pubkey' => $payrollBatch->anchor_batch_pubkey,
                'approval_root' => $approvalRoot,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: (string) $payrollBatch->anchor_batch_pubkey,
            txSignature: $this->token('BatchApproveTx', $payrollBatch->id, 64),
            authorityPubkey: $this->token('AuthorityPda', $company->id),
            approvedAt: 1770000000 + $payrollBatch->id,
        );
    }

    public function finalizePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $settlementRoot,
    ): AnchorInstructionResult {
        $this->maybeFail('finalizePayrollBatch');
        $this->calls[] = [
            'name' => 'finalizePayrollBatch',
            'payload' => [
                'company_id' => $company->id,
                'payroll_batch_id' => $payrollBatch->id,
                'anchor_batch_pubkey' => $payrollBatch->anchor_batch_pubkey,
                'settlement_root' => $settlementRoot,
            ],
        ];

        return new AnchorInstructionResult(
            companyPubkey: $this->token('CompanyPda', $company->id),
            accountPubkey: (string) $payrollBatch->anchor_batch_pubkey,
            txSignature: $this->token('BatchFinalizeTx', $payrollBatch->id, 64),
            authorityPubkey: $this->token('AuthorityPda', $company->id),
            finalizedBy: $this->token('AuthorityPda', $company->id),
            executedAt: 1770003600 + $payrollBatch->id,
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
