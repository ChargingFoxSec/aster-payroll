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
use Throwable;

class ThrowingPayrollAnchorClient implements PayrollAnchorClient
{
    public function __construct(
        private readonly Throwable $throwable,
    ) {}

    public function createEmploymentContract(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        string $currentCompensationRef,
    ): AnchorInstructionResult {
        $this->fail();
    }

    public function amendCompensation(
        Company $company,
        Employee $employee,
        EmploymentContract $contract,
        CompensationAmendment $amendment,
        string $amendmentHash,
    ): AnchorInstructionResult {
        $this->fail();
    }

    public function commitPayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        Collection $entries,
        string $entriesRoot,
        int $entryCount,
    ): AnchorInstructionResult {
        $this->fail();
    }

    public function approvePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $approvalRoot,
    ): AnchorInstructionResult {
        $this->fail();
    }

    public function finalizePayrollBatch(
        Company $company,
        PayrollBatch $payrollBatch,
        string $settlementRoot,
    ): AnchorInstructionResult {
        $this->fail();
    }

    private function fail(): never
    {
        throw $this->throwable;
    }
}
