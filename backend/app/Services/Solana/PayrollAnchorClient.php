<?php

namespace App\Services\Solana;

use App\Models\Company;
use App\Models\CompensationAmendment;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollBatch;
use Illuminate\Support\Collection;

interface PayrollAnchorClient
{
    public function createEmploymentContract(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        string $currentCompensationRef,
    ): AnchorInstructionResult;

    public function amendCompensation(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        CompensationAmendment $amendment,
        string $amendmentHash,
    ): AnchorInstructionResult;

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    public function commitPayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        Collection $entries,
        string $entriesRoot,
        int $entryCount,
    ): AnchorInstructionResult;

    public function approvePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $approvalRoot,
    ): AnchorInstructionResult;

    public function finalizePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $settlementRoot,
    ): AnchorInstructionResult;
}
