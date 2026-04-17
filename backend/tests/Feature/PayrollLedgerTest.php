<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Payroll\PayrollReceiptImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_confidential_receipt_can_be_imported_into_payroll_ledger(): void
    {
        $company = $this->demoCompany();
        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'full_name' => 'Ari Chen',
            'email' => 'ari@example.com',
            'wallet_address' => 'Wallet22222222222222222222222222222222222222',
            'employment_status' => 'active',
            'start_date' => '2026-04-01',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);

        $entry = app(PayrollReceiptImportService::class)->importForEmployee(
            $company,
            $employee,
            $this->demoReceipt(),
            '2026-04-30',
        );

        $this->assertDatabaseHas('payroll_batches', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
            'status' => 'executed',
            'total_amount_minor' => 25000,
            'currency' => 'USDC',
        ]);

        $this->assertDatabaseHas('payroll_entries', [
            'payroll_batch_id' => $entry->payrollBatch->id,
            'employee_id' => $employee->id,
            'amount_minor' => 25000,
            'status' => 'paid',
            'currency' => 'USDC',
            'tx_signature' => 'DemoConfidentialTransferSignature111111111111111111111111111111',
        ]);
    }

    public function test_payroll_pages_render_imported_entries(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'full_name' => 'Jules Park',
            'email' => 'jules@example.com',
            'wallet_address' => null,
            'employment_status' => 'active',
            'start_date' => '2026-04-02',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);

        $entry = app(PayrollReceiptImportService::class)->importForEmployee(
            $company,
            $employee,
            $this->demoReceipt(),
            '2026-04-30',
        );

        $this->get(route('payroll-batches.index'))
            ->assertOk()
            ->assertSee('2026-04')
            ->assertSee('250.00 USDC');

        $this->get(route('payroll-batches.show', $entry->payrollBatch))
            ->assertOk()
            ->assertSee('Jules Park')
            ->assertSee('DemoConfidentialTransferSignature111111111111111111111111111111');

        $this->get(route('employees.payroll.show', $employee))
            ->assertOk()
            ->assertSee('Jules Park')
            ->assertSee('Paid')
            ->assertSee('DemoConfidentialTransferSignature111111111111111111111111111111');
    }

    private function demoReceipt(): array
    {
        return [
            'generated_at' => '2026-04-13T05:29:26Z',
            'token' => [
                'mint' => 'DemoMint111111111111111111111111111111111111111',
                'decimals' => 2,
                'company_token_account' => 'DemoCompanyToken11111111111111111111111111111111',
                'employee_token_account' => 'DemoEmployeeToken1111111111111111111111111111111',
            ],
            'payroll' => [
                'minted_amount' => 1000,
                'confidential_transfer_amount' => 250,
            ],
            'transactions' => [
                'confidential_transfer' => 'DemoConfidentialTransferSignature111111111111111111111111111111',
            ],
        ];
    }
}
