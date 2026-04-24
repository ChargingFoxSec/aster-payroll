<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollBatch;
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

    public function test_admin_can_prepare_batch_manifests_and_import_receipts_until_the_batch_is_executed(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $firstEmployee = $this->createEmployeeWithCompensation($company->id, 'Ari Chen', 'ari@example.com', 250000);
        $secondEmployee = $this->createEmployeeWithCompensation($company->id, 'Mina Patel', 'mina@example.com', 320000);

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = PayrollBatch::query()
            ->with('entries')
            ->firstOrFail();

        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
        ])->assertRedirect(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]));

        $executions = PayoutExecution::query()
            ->with(['payrollEntry.payrollBatch', 'employee'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $executions);

        foreach ($executions as $execution) {
            Storage::disk('local')->assertExists($execution->prepared_payload_path);
            $preparedPayload = json_decode(Storage::disk('local')->get($execution->prepared_payload_path), true);

            $this->assertSame($execution->id, data_get($preparedPayload, 'execution.execution_id'));
            $this->assertSame($execution->payroll_entry_id, data_get($preparedPayload, 'execution.payroll_entry_id'));
            $this->assertSame($batch->id, data_get($preparedPayload, 'execution.payroll_batch_id'));
            $this->assertSame(
                "payout-execution-{$execution->id}-manifest.json",
                data_get($preparedPayload, 'artifacts.manifest_download_name'),
            );
            $this->assertSame('./onchain/scripts/confidential-payroll-poc.sh', data_get($preparedPayload, 'artifacts.helper_script'));
            $this->assertStringNotContainsString((string) storage_path(), json_encode($preparedPayload));
            $this->assertStringNotContainsString('/workspaces/frontiers-hackathon', json_encode($preparedPayload));
        }

        $firstExecution = $executions->firstWhere('employee_id', $firstEmployee->id);
        $secondExecution = $executions->firstWhere('employee_id', $secondEmployee->id);

        $this->assertNotNull($firstExecution);
        $this->assertNotNull($secondExecution);

        $this->importReceiptForExecution($firstExecution, $firstEmployee->full_name, '250000', '2500', 'FirstFixtureTransferSignature1111111111111111111111111');

        $batch->refresh();
        $this->assertSame(PayrollBatch::STATUS_PARTIALLY_PAID, $batch->status);
        $this->assertNull($batch->latestExecutionAttestation);

        $response = $this->importReceiptForExecution($secondExecution, $secondEmployee->full_name, '320000', '3200', 'SecondFixtureTransferSignature111111111111111111111111');

        $response->assertRedirect(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]));

        $batch->refresh();
        $this->assertSame(PayrollBatch::STATUS_EXECUTED, $batch->status);
        $this->assertNotNull($batch->executed_at);
        $this->assertDatabaseHas('attestations', [
            'payroll_batch_id' => $batch->id,
            'attestation_type' => 'payroll_batch_executed',
        ]);

        $this->assertSame(
            ['createPayrollBatch', 'markPayrollBatchExecuted'],
            array_column($this->payrollAnchorClient()->calls, 'name'),
        );

        $this->get(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]))
            ->assertOk()
            ->assertSee('Receipt confidential amount')
            ->assertSee('2,500.00 USDC')
            ->assertSee('3,200.00 USDC')
            ->assertSee('(2500 private units)')
            ->assertSee('Employee public balance')
            ->assertSee('0');
    }

    public function test_repeated_batch_prepare_reuses_existing_executions_and_skips_imported_entries(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $firstEmployee = $this->createEmployeeWithCompensation($company->id, 'Lio Hart', 'lio@example.com', 180000);
        $secondEmployee = $this->createEmployeeWithCompensation($company->id, 'Rin Soto', 'rin@example.com', 190000);

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = PayrollBatch::query()->firstOrFail();

        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
        ]);

        $initialExecutions = PayoutExecution::query()->orderBy('id')->get();
        $this->assertCount(2, $initialExecutions);

        $firstExecution = $initialExecutions->firstWhere('employee_id', $firstEmployee->id);
        $secondExecution = $initialExecutions->firstWhere('employee_id', $secondEmployee->id);

        $this->assertNotNull($firstExecution);
        $this->assertNotNull($secondExecution);

        $this->importReceiptForExecution($firstExecution, $firstEmployee->full_name, '180000', '1800', 'ReuseFirstTransferSignature11111111111111111111111111');

        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
        ])->assertRedirect(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]));

        $executions = PayoutExecution::query()->orderBy('id')->get();
        $this->assertCount(2, $executions);
        $this->assertSame($firstExecution->id, $executions->firstWhere('employee_id', $firstEmployee->id)?->id);
        $this->assertSame($secondExecution->id, $executions->firstWhere('employee_id', $secondEmployee->id)?->id);

        $this->assertDatabaseHas('payout_executions', [
            'id' => $firstExecution->id,
            'status' => PayoutExecution::STATUS_IMPORTED,
        ]);
        $this->assertDatabaseHas('payout_executions', [
            'id' => $secondExecution->id,
            'status' => PayoutExecution::STATUS_AWAITING_APPROVAL,
        ]);
    }

    public function test_receipt_import_rejects_a_mismatched_fixture_receipt_without_affecting_other_batch_entries(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $firstEmployee = $this->createEmployeeWithCompensation($company->id, 'Lio Hart', 'lio@example.com', 180000);
        $secondEmployee = $this->createEmployeeWithCompensation($company->id, 'Nora Bell', 'nora@example.com', 220000);

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = PayrollBatch::query()->firstOrFail();

        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
        ]);

        $execution = PayoutExecution::query()
            ->with('payrollEntry')
            ->where('employee_id', $firstEmployee->id)
            ->firstOrFail();
        $otherExecution = PayoutExecution::query()
            ->where('employee_id', $secondEmployee->id)
            ->firstOrFail();

        $receiptUpload = UploadedFile::fake()->createWithContent(
            'receipt.json',
            $this->renderFixture('imported-receipt.template.json', [
                '__EXECUTION_ID__' => (string) ($execution->id + 99),
                '__PAYROLL_ENTRY_ID__' => (string) $execution->payroll_entry_id,
                '__PAYROLL_BATCH_ID__' => (string) $execution->payrollEntry->payroll_batch_id,
                '__EMPLOYEE_NAME__' => $firstEmployee->full_name,
                '__AMOUNT_MINOR__' => '180000',
                '__TRANSFER_AMOUNT__' => '1800',
                '__TX_SIGNATURE__' => 'RejectedTransferSignature11111111111111111111111111',
            ]),
        );

        $this->post(route('payroll-demo.import'), [
            'payout_execution_id' => $execution->id,
            'receipt' => $receiptUpload,
        ])
            ->assertRedirect(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]));

        $execution->refresh();
        $otherExecution->refresh();

        $this->assertSame(PayoutExecution::STATUS_FAILED, $execution->status);
        $this->assertStringContainsString('does not match the prepared payout', (string) $execution->failure_reason);
        $this->assertSame(PayoutExecution::STATUS_AWAITING_APPROVAL, $otherExecution->status);

        $this->assertDatabaseHas('payroll_entries', [
            'id' => $execution->payroll_entry_id,
            'status' => 'draft',
            'tx_signature' => null,
        ]);
        $this->assertDatabaseHas('payroll_entries', [
            'id' => $otherExecution->payroll_entry_id,
            'status' => 'draft',
            'tx_signature' => null,
        ]);
    }

    public function test_final_receipt_import_keeps_local_batch_state_when_executed_attestation_fails(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeWithCompensation($company->id, 'Casey Lane', 'casey@example.com', 175000);

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = PayrollBatch::query()->firstOrFail();

        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
        ]);

        $this->payrollAnchorClient()->failOn(
            'markPayrollBatchExecuted',
            new RuntimeException('anchor executed sync unavailable'),
        );

        $execution = PayoutExecution::query()->firstOrFail();
        $response = $this->importReceiptForExecution(
            $execution,
            $employee->full_name,
            '175000',
            '1750',
            'FinalLocalOnlyTransferSignature11111111111111111111111',
        );

        $response
            ->assertRedirect(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, __('ui.messages.batch_reconciled', ['period' => '2026-04']))
                && str_contains($status, __('ui.messages.payroll_batch_execution_attestation_failed')));

        $batch->refresh();
        $this->assertSame(PayrollBatch::STATUS_EXECUTED, $batch->status);
        $this->assertNotNull($batch->executed_at);
        $this->assertDatabaseMissing('attestations', [
            'payroll_batch_id' => $batch->id,
            'attestation_type' => 'payroll_batch_executed',
        ]);
    }

    public function test_unexpected_prepare_errors_do_not_flash_raw_exception_details(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeWithCompensation($company->id, 'Casey Lane', 'casey@example.com', 175000);

        $batch = app(\App\Services\Payroll\PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $mock = Mockery::mock(ConfidentialPayrollService::class);
        $mock->shouldReceive('prepareBatchExecutions')
            ->once()
            ->andThrow(new RuntimeException('sensitive /tmp/prepared-payouts/execution-99.json'));
        $mock->shouldReceive('latestExecution')
            ->andReturn(null);
        app()->instance(ConfidentialPayrollService::class, $mock);

        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
        ])
            ->assertRedirect(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]))
            ->assertSessionHas('error', __('ui.messages.prepare_manifest_failed'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email' => 'casey@example.com',
        ]);
    }

    private function importReceiptForExecution(
        PayoutExecution $execution,
        string $employeeName,
        string $amountMinor,
        string $transferAmount,
        string $txSignature,
    ) {
        $execution->loadMissing(['payrollEntry.payrollBatch']);

        $receiptUpload = UploadedFile::fake()->createWithContent(
            'receipt.json',
            $this->renderFixture('imported-receipt.template.json', [
                '__EXECUTION_ID__' => (string) $execution->id,
                '__PAYROLL_ENTRY_ID__' => (string) $execution->payroll_entry_id,
                '__PAYROLL_BATCH_ID__' => (string) $execution->payrollEntry->payroll_batch_id,
                '__EMPLOYEE_NAME__' => $employeeName,
                '__AMOUNT_MINOR__' => $amountMinor,
                '__TRANSFER_AMOUNT__' => $transferAmount,
                '__TX_SIGNATURE__' => $txSignature,
            ]),
        );

        return $this->post(route('payroll-demo.import'), [
            'payout_execution_id' => $execution->id,
            'receipt' => $receiptUpload,
        ]);
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
