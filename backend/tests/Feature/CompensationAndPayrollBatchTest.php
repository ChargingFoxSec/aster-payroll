<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Payroll\PayrollBatchDraftService;
use App\Services\Payroll\PayrollReceiptImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompensationAndPayrollBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_a_compensation_update(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployee($company->id, 'Mina Patel', 'mina@example.com');
        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/mina-v1.pdf',
            'file_hash' => str_repeat('a', 64),
            'title' => 'Mina Patel Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $response = $this->post(route('employees.compensation-amendments.store', $employee), [
            'new_amount' => '2500.00',
            'effective_date' => '2026-04-15',
            'reason' => 'Initial offer',
        ]);

        $response->assertRedirect(route('employees.show', $employee));

        $amendment = $employee->compensationAmendments()->firstOrFail();

        $this->assertDatabaseHas('compensation_amendments', [
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => 250000,
            'currency' => 'USDC',
            'reason' => 'Initial offer',
        ]);
        $this->assertSame('2026-04-15', $amendment->effective_date->toDateString());
    }

    public function test_admin_can_draft_a_payroll_batch_from_latest_compensation_records(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $firstEmployee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $secondEmployee = $this->createEmployee($company->id, 'Lio Hart', 'lio@example.com');

        $firstAmendment = $this->createCompensation($company->id, $firstEmployee, 240000, '2026-04-01');
        $secondAmendment = $this->createCompensation($company->id, $secondEmployee, 320000, '2026-04-10');

        $response = $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = $company->payrollBatches()->firstOrFail();

        $response->assertRedirect(route('payroll-batches.show', $batch));

        $this->assertDatabaseHas('payroll_batches', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
            'status' => 'draft',
            'total_amount_minor' => 560000,
        ]);
        $this->assertSame('2026-04-30', $batch->due_date->toDateString());

        $this->assertDatabaseHas('payroll_entries', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $firstEmployee->id,
            'compensation_amendment_id' => $firstAmendment->id,
            'amount_minor' => 240000,
            'status' => 'draft',
            'tx_signature' => null,
        ]);

        $this->assertDatabaseHas('payroll_entries', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $secondEmployee->id,
            'compensation_amendment_id' => $secondAmendment->id,
            'amount_minor' => 320000,
            'status' => 'draft',
            'tx_signature' => null,
        ]);
    }

    public function test_receipt_import_keeps_the_compensation_link_when_updating_a_draft_entry(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployee($company->id, 'Rin Soto', 'rin@example.com');
        $amendment = $this->createCompensation($company->id, $employee, 250000, '2026-04-01');

        app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $entry = app(PayrollReceiptImportService::class)->importForEmployee(
            $company,
            $employee,
            [
                'generated_at' => '2026-04-30T10:15:00Z',
                'token' => [
                    'decimals' => 2,
                ],
                'payroll' => [
                    'confidential_transfer_amount' => 2500,
                ],
                'transactions' => [
                    'confidential_transfer' => '5tX4uEqJ2cQ8Q4pV5BohM6yJx7YkntrGUPmX1MockSig',
                ],
            ],
            '2026-04-30',
        );

        $this->assertSame($amendment->id, $entry->compensation_amendment_id);
        $this->assertSame('paid', $entry->status);
        $this->assertSame('5tX4uEqJ2cQ8Q4pV5BohM6yJx7YkntrGUPmX1MockSig', $entry->tx_signature);

        $this->assertDatabaseHas('payroll_entries', [
            'id' => $entry->id,
            'compensation_amendment_id' => $amendment->id,
            'status' => 'paid',
        ]);
    }

    private function createEmployee(int $companyId, string $fullName, string $email): Employee
    {
        return Employee::query()->create([
            'company_id' => $companyId,
            'full_name' => $fullName,
            'email' => $email,
            'wallet_address' => null,
            'employment_status' => 'active',
            'start_date' => '2026-04-01',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);
    }

    private function createCompensation(int $companyId, Employee $employee, int $amountMinor, string $effectiveDate)
    {
        $version = $employee->contracts()->count() + 1;

        $contract = $employee->contracts()->create([
            'company_id' => $companyId,
            'version' => $version,
            'file_path' => "contracts/{$employee->id}-{$version}.pdf",
            'file_hash' => str_pad((string) $employee->id, 64, '0', STR_PAD_LEFT),
            'title' => "{$employee->full_name} Employment Contract",
            'effective_date' => $effectiveDate,
            'status' => 'active',
        ]);

        return $employee->compensationAmendments()->create([
            'company_id' => $companyId,
            'contract_id' => $contract->id,
            'previous_amount_minor' => $employee->compensationAmendments()->latest('id')->value('new_amount_minor'),
            'new_amount_minor' => $amountMinor,
            'currency' => 'USDC',
            'effective_date' => $effectiveDate,
            'reason' => 'Compensation update',
        ]);
    }
}
