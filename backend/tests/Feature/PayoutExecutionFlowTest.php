<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayoutExecution;
use App\Services\Payroll\ConfidentialPayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PayoutExecutionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_prepare_and_import_a_fixture_receipt_for_a_payout_execution(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeWithCompensation($company->id, 'Ari Chen', 'ari@example.com', 250000);

        $this->post(route('payroll-demo.prepare'), [
            'employee_id' => $employee->id,
            'due_date' => '2026-04-30',
        ])->assertRedirect(route('payroll-demo.show'));

        $execution = PayoutExecution::query()
            ->with(['payrollEntry.payrollBatch', 'employee'])
            ->firstOrFail();

        Storage::disk('local')->assertExists($execution->prepared_payload_path);
        $preparedPayload = json_decode(Storage::disk('local')->get($execution->prepared_payload_path), true);

        $this->assertSame($execution->id, data_get($preparedPayload, 'execution.execution_id'));
        $this->assertSame($execution->payroll_entry_id, data_get($preparedPayload, 'execution.payroll_entry_id'));
        $this->assertSame(250000, data_get($preparedPayload, 'payroll.amount_minor'));
        $this->assertSame("payout-execution-{$execution->id}-manifest.json", data_get($preparedPayload, 'artifacts.manifest_download_name'));
        $this->assertSame('./onchain/scripts/confidential-payroll-poc.sh', data_get($preparedPayload, 'artifacts.helper_script'));
        $this->assertNull(data_get($preparedPayload, 'artifacts.prepared_payload_path'));
        $this->assertStringNotContainsString((string) storage_path(), json_encode($preparedPayload));
        $this->assertStringNotContainsString('/workspaces/frontiers-hackathon', json_encode($preparedPayload));

        $this->get(route('payroll-demo.executions.manifest', $execution))
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->get(route('payroll-demo.show'))
            ->assertOk()
            ->assertSee('Download manifest JSON')
            ->assertDontSee((string) storage_path('app/private'))
            ->assertDontSee('/workspaces/frontiers-hackathon')
            ->assertDontSee((string) config('payroll.confidential.poc_script'));

        $receiptUpload = UploadedFile::fake()->createWithContent(
            'receipt.json',
            $this->renderFixture('imported-receipt.template.json', [
                '__EXECUTION_ID__' => (string) $execution->id,
                '__PAYROLL_ENTRY_ID__' => (string) $execution->payroll_entry_id,
                '__PAYROLL_BATCH_ID__' => (string) $execution->payrollEntry->payroll_batch_id,
                '__EMPLOYEE_NAME__' => $employee->full_name,
                '__AMOUNT_MINOR__' => '250000',
                '__TRANSFER_AMOUNT__' => '2500',
                '__TX_SIGNATURE__' => 'FixtureTransferSignature111111111111111111111111111111',
            ]),
        );

        $this->post(route('payroll-demo.import'), [
            'payout_execution_id' => $execution->id,
            'receipt' => $receiptUpload,
        ])
            ->assertRedirect(route('payroll-batches.show', $execution->payrollEntry->payrollBatch));

        $this->assertDatabaseHas('payout_executions', [
            'id' => $execution->id,
            'status' => PayoutExecution::STATUS_IMPORTED,
            'approved_wallet_address' => 'AdminWallet111111111111111111111111111111111',
            'tx_signature' => 'FixtureTransferSignature111111111111111111111111111111',
        ]);

        $this->assertDatabaseHas('payroll_entries', [
            'id' => $execution->payroll_entry_id,
            'status' => 'paid',
            'tx_signature' => 'FixtureTransferSignature111111111111111111111111111111',
        ]);

        $execution->refresh();
        Storage::disk('local')->assertExists($execution->receipt_path);
    }

    public function test_receipt_import_rejects_a_mismatched_fixture_receipt(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeWithCompensation($company->id, 'Lio Hart', 'lio@example.com', 180000);
        $execution = app(ConfidentialPayrollService::class)->prepareExecution($company, $employee, '2026-04-30');

        $receiptUpload = UploadedFile::fake()->createWithContent(
            'receipt.json',
            $this->renderFixture('imported-receipt.template.json', [
                '__EXECUTION_ID__' => (string) ($execution->id + 99),
                '__PAYROLL_ENTRY_ID__' => (string) $execution->payroll_entry_id,
                '__PAYROLL_BATCH_ID__' => (string) $execution->payrollEntry->payroll_batch_id,
                '__EMPLOYEE_NAME__' => $employee->full_name,
                '__AMOUNT_MINOR__' => '180000',
                '__TRANSFER_AMOUNT__' => '1800',
                '__TX_SIGNATURE__' => 'RejectedTransferSignature11111111111111111111111111',
            ]),
        );

        $this->post(route('payroll-demo.import'), [
            'payout_execution_id' => $execution->id,
            'receipt' => $receiptUpload,
        ])
            ->assertRedirect(route('payroll-demo.show'));

        $execution->refresh();

        $this->assertSame(PayoutExecution::STATUS_FAILED, $execution->status);
        $this->assertStringContainsString('does not match the prepared payout', (string) $execution->failure_reason);

        $this->assertDatabaseHas('payroll_entries', [
            'id' => $execution->payroll_entry_id,
            'status' => 'draft',
            'tx_signature' => null,
        ]);
    }

    public function test_unexpected_prepare_errors_do_not_flash_raw_exception_details(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeWithCompensation($company->id, 'Casey Lane', 'casey@example.com', 175000);

        $mock = Mockery::mock(ConfidentialPayrollService::class);
        $mock->shouldReceive('prepareExecution')
            ->once()
            ->andThrow(new RuntimeException('sensitive /tmp/prepared-payouts/execution-99.json'));
        app()->instance(ConfidentialPayrollService::class, $mock);

        $this->post(route('payroll-demo.prepare'), [
            'employee_id' => $employee->id,
            'due_date' => '2026-04-30',
        ])
            ->assertRedirect(route('payroll-demo.show'))
            ->assertSessionHas('error', 'Could not prepare the payout manifest. Check the application logs and try again.');
    }

    private function createEmployeeWithCompensation(int $companyId, string $fullName, string $email, int $amountMinor): Employee
    {
        $employee = Employee::query()->create([
            'company_id' => $companyId,
            'full_name' => $fullName,
            'email' => $email,
            'wallet_address' => null,
            'employment_status' => 'active',
            'start_date' => '2026-04-01',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);

        $contract = $employee->contracts()->create([
            'company_id' => $companyId,
            'version' => 1,
            'file_path' => "contracts/{$employee->id}-1.pdf",
            'file_hash' => str_pad((string) $employee->id, 64, '0', STR_PAD_LEFT),
            'title' => "{$employee->full_name} Employment Contract",
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $employee->compensationAmendments()->create([
            'company_id' => $companyId,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => $amountMinor,
            'currency' => 'USDC',
            'effective_date' => '2026-04-01',
            'reason' => 'Fixture compensation',
        ]);

        return $employee;
    }

    private function renderFixture(string $filename, array $replacements): string
    {
        $template = file_get_contents($this->fixturePath($filename));

        return strtr((string) $template, $replacements);
    }

    private function fixturePath(string $filename): string
    {
        return base_path("tests/Fixtures/payroll/{$filename}");
    }
}
