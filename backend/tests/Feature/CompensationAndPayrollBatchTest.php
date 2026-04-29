<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryProof;
use App\Services\Payroll\PayrollBatchDraftService;
use App\Services\Payroll\PayrollBatchProofService;
use App\Services\Payroll\PayrollReceiptImportService;
use App\Services\Payroll\PayrollStatusService;
use App\Services\Solana\PayrollAnchoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CompensationAndPayrollBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_a_compensation_update(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployee($company->id, 'Mina Patel', 'mina@example.com', 'MinaWallet111111111111111111111111111111111111');
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

        $response->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', __('ui.messages.compensation_recorded', ['employee' => 'Mina Patel']));

        $amendment = $employee->compensationAmendments()->firstOrFail();
        $contract->refresh();

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
        $amendment->refresh();
        $this->assertNotNull($contract->anchor_contract_pubkey);
        $this->assertNotNull($amendment->anchor_amendment_pubkey);
        $this->assertDatabaseHas('attestations', [
            'contract_id' => $contract->id,
            'attestation_type' => 'employment_contract_anchor',
        ]);
        $this->assertDatabaseHas('attestations', [
            'compensation_amendment_id' => $amendment->id,
            'attestation_type' => 'compensation_amendment_anchor',
        ]);
        $this->assertSame(
            ['createEmploymentContract', 'amendCompensation'],
            array_column($this->payrollAnchorClient()->calls, 'name'),
        );
    }

    public function test_compensation_update_stays_saved_when_anchor_sync_returns_a_user_facing_error(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployee($company->id, 'Mina Patel', 'mina@example.com', 'MinaWallet111111111111111111111111111111111111');
        $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/mina-v1.pdf',
            'file_hash' => str_repeat('a', 64),
            'title' => 'Mina Patel Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $this->bindThrowingPayrollAnchorClient(new UserFacingException('Onchain anchoring is temporarily unavailable.'));

        $response = $this->post(route('employees.compensation-amendments.store', $employee), [
            'new_amount' => '2500.00',
            'effective_date' => '2026-04-15',
            'reason' => 'Initial offer',
        ]);

        $response->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, __('ui.messages.compensation_recorded', ['employee' => 'Mina Patel']))
                && str_contains($status, 'Onchain anchoring is temporarily unavailable.'));

        $this->assertDatabaseHas('compensation_amendments', [
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'new_amount_minor' => 250000,
        ]);
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
        $batch->refresh();
        $this->assertNotNull($batch->anchor_batch_pubkey);
        $this->assertSame(2, $batch->entry_count);
        $this->assertNotNull($batch->entries_root);
        $this->assertDatabaseHas('attestations', [
            'payroll_batch_id' => $batch->id,
            'attestation_type' => 'payroll_batch_commit',
        ]);
        $this->assertDatabaseCount('payroll_entry_proofs', 2);

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

    public function test_payroll_batch_uses_admin_recorded_salary_raise_after_effective_date(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = $this->createEmployee($company->id, 'Raise Target', 'raise-target@example.com', 'RaiseWallet111111111111111111111111111111111111');
        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/raise-target-v1.pdf',
            'file_hash' => str_repeat('b', 64),
            'title' => 'Raise Target Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $baseline = $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => 250000,
            'currency' => 'USDC',
            'effective_date' => '2026-04-01',
            'reason' => 'Baseline salary',
        ]);

        $this->post(route('employees.compensation-amendments.store', $employee), [
            'new_amount' => '3000.00',
            'effective_date' => '2026-05-01',
            'reason' => 'Performance raise',
        ])
            ->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', __('ui.messages.compensation_recorded', ['employee' => 'Raise Target']));

        $raise = $employee->compensationAmendments()
            ->where('new_amount_minor', 300000)
            ->firstOrFail();

        $this->assertSame($baseline->new_amount_minor, $raise->previous_amount_minor);
        $this->assertSame($contract->id, $raise->contract_id);
        $this->assertSame('2026-05-01', $raise->effective_date->toDateString());
        $this->assertNotNull($raise->fresh()->anchor_amendment_pubkey);

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-05',
            'due_date' => '2026-05-31',
        ]);

        $batch = PayrollBatch::query()
            ->with('entries')
            ->where('period_year', 2026)
            ->where('period_month', 5)
            ->firstOrFail();

        $this->assertDatabaseHas('payroll_entries', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $employee->id,
            'compensation_amendment_id' => $raise->id,
            'amount_minor' => 300000,
            'status' => 'draft',
        ]);
        $this->assertSame(300000, $batch->entries->sum('amount_minor'));
        $this->assertSame(
            ['createEmploymentContract', 'amendCompensation', 'commitPayrollBatch'],
            array_column($this->payrollAnchorClient()->calls, 'name'),
        );
    }

    public function test_payroll_batch_uses_admin_recorded_salary_decrease_after_effective_date(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = $this->createEmployee($company->id, 'Decrease Target', 'decrease-target@example.com', 'DecreaseWallet111111111111111111111111111111111');
        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/decrease-target-v1.pdf',
            'file_hash' => str_repeat('c', 64),
            'title' => 'Decrease Target Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $baseline = $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => 300000,
            'currency' => 'USDC',
            'effective_date' => '2026-04-01',
            'reason' => 'Baseline salary',
        ]);

        $this->post(route('employees.compensation-amendments.store', $employee), [
            'new_amount' => '2500.00',
            'effective_date' => '2026-05-01',
            'reason' => 'Compensation adjustment',
        ])
            ->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', __('ui.messages.compensation_recorded', ['employee' => 'Decrease Target']));

        $decrease = $employee->compensationAmendments()
            ->where('new_amount_minor', 250000)
            ->firstOrFail();

        $this->assertSame($baseline->new_amount_minor, $decrease->previous_amount_minor);
        $this->assertSame($contract->id, $decrease->contract_id);
        $this->assertSame('2026-05-01', $decrease->effective_date->toDateString());
        $this->assertNotNull($decrease->fresh()->anchor_amendment_pubkey);

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-05',
            'due_date' => '2026-05-31',
        ]);

        $batch = PayrollBatch::query()
            ->with('entries')
            ->where('period_year', 2026)
            ->where('period_month', 5)
            ->firstOrFail();

        $this->assertDatabaseHas('payroll_entries', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $employee->id,
            'compensation_amendment_id' => $decrease->id,
            'amount_minor' => 250000,
            'status' => 'draft',
        ]);
        $this->assertSame(250000, $batch->entries->sum('amount_minor'));
        $this->assertSame(
            ['createEmploymentContract', 'amendCompensation', 'commitPayrollBatch'],
            array_column($this->payrollAnchorClient()->calls, 'name'),
        );
    }

    public function test_payroll_batch_draft_stays_saved_when_anchor_sync_returns_a_user_facing_error(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-04-01');
        $this->bindThrowingPayrollAnchorClient(new UserFacingException('Onchain payroll batch anchoring is temporarily unavailable.'));

        $response = $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = $company->payrollBatches()->firstOrFail();

        $response->assertRedirect(route('payroll-batches.show', $batch))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, __('ui.messages.payroll_batch_drafted', ['period' => '2026-04']))
                && str_contains($status, 'Onchain payroll batch anchoring is temporarily unavailable.'));

        $this->assertDatabaseHas('payroll_batches', [
            'id' => $batch->id,
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);
        $this->assertNull($batch->fresh()->anchor_batch_pubkey);
    }

    public function test_payroll_batch_due_date_must_fall_inside_selected_period(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-05-01');

        $this->from(route('payroll-batches.index'))->post(route('payroll-batches.store'), [
            'period' => '2026-07',
            'due_date' => '2026-04-30',
        ])
            ->assertRedirect(route('payroll-batches.index'))
            ->assertSessionHasErrors([
                'due_date' => __('ui.messages.payroll_due_date_must_match_period'),
            ]);

        $this->assertDatabaseCount('payroll_batches', 0);
    }

    public function test_admin_cannot_refresh_a_batch_after_it_has_been_committed_onchain(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-04-01');

        $this->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ]);

        $batch = $company->payrollBatches()->firstOrFail();

        $this->from(route('payroll-batches.index'))->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-15',
        ])
            ->assertRedirect(route('payroll-batches.index'))
            ->assertSessionHas('error', __('ui.messages.payroll_batch_committed_and_frozen'));

        $batch->refresh();
        $this->assertSame('2026-04-30', $batch->due_date->toDateString());
    }

    public function test_committed_batch_sync_does_not_overwrite_entries_root_when_entries_change(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );
        app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch);

        $committedRoot = $batch->fresh()->entries_root;
        $entry = $batch->entries()->firstOrFail();
        $entry->forceFill(['amount_minor' => 250000])->save();

        $warning = app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch->fresh());

        $this->assertSame(__('ui.messages.payroll_entry_proof_mismatch'), $warning);
        $this->assertSame($committedRoot, $batch->fresh()->entries_root);
    }

    public function test_legacy_receipt_import_cannot_change_a_committed_batch(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployee($company->id, 'Rin Soto', 'rin@example.com');
        $this->createCompensation($company->id, $employee, 250000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );
        app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch);

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage(__('ui.messages.payroll_batch_committed_and_frozen'));

        app(PayrollReceiptImportService::class)->importForEmployee(
            $company,
            $employee,
            [
                'generated_at' => '2026-04-30T10:15:00Z',
                'token' => ['decimals' => 2],
                'payroll' => ['confidential_transfer_amount' => 2600],
                'transactions' => ['confidential_transfer' => 'BlockedLegacyImportSignature11111111111111111111'],
            ],
            '2026-04-30',
        );
    }

    public function test_commit_proof_is_deterministic_for_the_same_batch_entries(): void
    {
        $company = $this->demoCompany();
        $firstEmployee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $secondEmployee = $this->createEmployee($company->id, 'Lio Hart', 'lio@example.com');
        $thirdEmployee = $this->createEmployee($company->id, 'Mia Vale', 'mia@example.com');
        $this->createCompensation($company->id, $firstEmployee, 240000, '2026-04-01');
        $this->createCompensation($company->id, $secondEmployee, 320000, '2026-04-01');
        $this->createCompensation($company->id, $thirdEmployee, 410000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $proofService = app(PayrollBatchProofService::class);

        $firstProof = $proofService->commitProof($batch);
        $secondProof = $proofService->commitProof($batch->fresh('entries.employee', 'entries.compensationAmendment'));

        $this->assertSame(3, $firstProof['entry_count']);
        $this->assertSame($firstProof['entry_count'], $secondProof['entry_count']);
        $this->assertSame($firstProof['entries_root'], $secondProof['entries_root']);
        $this->assertSame($firstProof['leaves']->all(), $secondProof['leaves']->all());

        $storedProofs = PayrollEntryProof::query()
            ->where('payroll_batch_id', $batch->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(3, $storedProofs);
        $this->assertSame($firstProof['leaves']->all(), $storedProofs->pluck('leaf_hash')->all());
        $this->assertSame([0, 1, 2], $storedProofs->pluck('position')->all());
        $this->assertNotEmpty($storedProofs->first()?->proof_path);
        $storedProofs->each(
            fn (PayrollEntryProof $storedProof) => $this->assertTrue(
                $proofService->verifyMembership($storedProof, $firstProof['entries_root']),
            ),
        );
    }

    public function test_commit_proof_material_does_not_store_plaintext_salary_in_leaf_payload(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $proof = app(PayrollBatchProofService::class)->commitProof($batch);
        $storedProof = PayrollEntryProof::query()->where('payroll_batch_id', $batch->id)->firstOrFail();
        $leafPayload = $storedProof->leaf_payload;

        $this->assertSame($proof['leaves']->first(), $storedProof->leaf_hash);
        $this->assertSame('payroll_entry_commitment_v1', $storedProof->proof_version);
        $this->assertArrayHasKey('amount_commitment_hash', $leafPayload);
        $this->assertArrayNotHasKey('amount_minor', $leafPayload);
        $this->assertArrayNotHasKey('amount', $leafPayload);
        $this->assertStringNotContainsString('240000', json_encode($leafPayload, JSON_THROW_ON_ERROR));
        $this->assertSame(64, strlen($storedProof->amount_commitment_hash));
        $this->assertSame(64, strlen($storedProof->amount_nonce));
        $this->assertIsArray($storedProof->proof_path);
    }

    public function test_committed_batch_sync_warns_when_stored_entry_proof_material_is_tampered(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );
        app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch);

        $committedRoot = $batch->fresh()->entries_root;
        $proof = PayrollEntryProof::query()->where('payroll_batch_id', $batch->id)->firstOrFail();
        $proof->forceFill([
            'leaf_hash' => str_repeat('f', 64),
        ])->save();

        $warning = app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch->fresh());

        $this->assertSame(__('ui.messages.payroll_entry_proof_mismatch'), $warning);
        $this->assertSame($committedRoot, $batch->fresh()->entries_root);
    }

    public function test_committed_batch_sync_warns_when_entry_proof_material_is_replayed(): void
    {
        $company = $this->demoCompany();
        $firstEmployee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $secondEmployee = $this->createEmployee($company->id, 'Lio Hart', 'lio@example.com');
        $this->createCompensation($company->id, $firstEmployee, 240000, '2026-04-01');
        $this->createCompensation($company->id, $secondEmployee, 320000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );
        app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch);

        $proofs = PayrollEntryProof::query()
            ->where('payroll_batch_id', $batch->id)
            ->orderBy('position')
            ->get();
        $firstProof = $proofs->get(0);
        $secondProof = $proofs->get(1);

        $this->assertNotNull($firstProof);
        $this->assertNotNull($secondProof);

        $secondProof->forceFill([
            'proof_version' => $firstProof->proof_version,
            'employee_ref_hash' => $firstProof->employee_ref_hash,
            'compensation_ref_hash' => $firstProof->compensation_ref_hash,
            'amount_commitment_hash' => $firstProof->amount_commitment_hash,
            'amount_nonce' => $firstProof->amount_nonce,
            'leaf_hash' => $firstProof->leaf_hash,
            'leaf_payload' => $firstProof->leaf_payload,
        ])->save();

        $warning = app(PayrollAnchoringService::class)->syncCommittedPayrollBatch($batch->fresh());

        $this->assertSame(__('ui.messages.payroll_entry_proof_mismatch'), $warning);
    }

    public function test_admin_cannot_draft_a_batch_from_non_demo_currency_records(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'full_name' => 'Euro User',
            'email' => 'euro-user@example.com',
            'wallet_address' => null,
            'employment_status' => 'active',
            'start_date' => '2026-04-01',
            'pay_cycle' => 'monthly',
            'currency' => 'EUR',
        ]);
        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/euro-user-v1.pdf',
            'file_hash' => str_repeat('e', 64),
            'title' => 'Euro User Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => 240000,
            'currency' => 'EUR',
            'effective_date' => '2026-04-01',
            'reason' => 'Euro salary',
        ]);

        $this->from(route('payroll-batches.index'))->post(route('payroll-batches.store'), [
            'period' => '2026-04',
            'due_date' => '2026-04-30',
        ])
            ->assertRedirect(route('payroll-batches.index'))
            ->assertSessionHas('error', __('ui.messages.employee_currency_unsupported_for_batch_draft', ['currency' => 'USDC']));

        $this->assertDatabaseMissing('payroll_batches', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);
    }

    public function test_overdue_sync_marks_unpaid_entries_and_batch_as_overdue(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $this->createCompensation($company->id, $employee, 240000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $entry = $batch->entries->first();

        $this->assertNotNull($entry);
        $this->assertSame(PayrollBatch::STATUS_DRAFT, $batch->status);
        $this->assertSame(PayrollEntry::STATUS_DRAFT, $entry->status);

        Artisan::call('payroll:sync-overdue', ['--date' => '2026-05-03']);

        $this->assertStringContainsString('Synced 1 payroll batch(es).', Artisan::output());
        $this->assertSame(PayrollEntry::STATUS_OVERDUE, $entry->fresh()->status);
        $this->assertSame(PayrollBatch::STATUS_OVERDUE, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->executed_at);
    }

    public function test_overdue_sync_marks_partially_paid_batches_overdue_when_remaining_entries_pass_due_date(): void
    {
        $company = $this->demoCompany();
        $firstEmployee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $secondEmployee = $this->createEmployee($company->id, 'Lio Hart', 'lio@example.com');
        $this->createCompensation($company->id, $firstEmployee, 240000, '2026-04-01');
        $this->createCompensation($company->id, $secondEmployee, 320000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $entries = $batch->entries->values();
        $firstEntry = $entries->get(0);
        $secondEntry = $entries->get(1);

        $this->assertNotNull($firstEntry);
        $this->assertNotNull($secondEntry);

        $firstEntry->forceFill([
            'status' => PayrollEntry::STATUS_PAID,
            'paid_at' => '2026-04-20 10:00:00',
            'tx_signature' => 'first-paid-signature',
        ])->save();

        app(PayrollStatusService::class)->syncBatch($batch, '2026-04-20');
        $this->assertSame(PayrollBatch::STATUS_PARTIALLY_PAID, $batch->fresh()->status);

        Artisan::call('payroll:sync-overdue', ['--date' => '2026-05-03']);

        $batch->refresh();
        $this->assertSame(PayrollBatch::STATUS_OVERDUE, $batch->status);
        $this->assertSame(PayrollEntry::STATUS_PAID, $firstEntry->fresh()->status);
        $this->assertSame(PayrollEntry::STATUS_OVERDUE, $secondEntry->fresh()->status);
        $this->assertNull($batch->executed_at);
    }

    public function test_status_sync_only_marks_a_batch_executed_when_all_entries_are_paid(): void
    {
        $company = $this->demoCompany();
        $firstEmployee = $this->createEmployee($company->id, 'Aki Noor', 'aki@example.com');
        $secondEmployee = $this->createEmployee($company->id, 'Lio Hart', 'lio@example.com');
        $this->createCompensation($company->id, $firstEmployee, 240000, '2026-04-01');
        $this->createCompensation($company->id, $secondEmployee, 320000, '2026-04-01');

        $batch = app(PayrollBatchDraftService::class)->createOrRefresh(
            $company,
            '2026-04',
            '2026-04-30',
        );

        $entries = $batch->entries->values();
        $firstEntry = $entries->get(0);
        $secondEntry = $entries->get(1);

        $this->assertNotNull($firstEntry);
        $this->assertNotNull($secondEntry);

        $firstEntry->forceFill([
            'status' => PayrollEntry::STATUS_PAID,
            'paid_at' => '2026-04-20 10:00:00',
            'tx_signature' => 'first-paid-signature',
        ])->save();

        app(PayrollStatusService::class)->syncBatch($batch, '2026-04-20');

        $batch->refresh();
        $this->assertSame(PayrollBatch::STATUS_PARTIALLY_PAID, $batch->status);
        $this->assertNull($batch->executed_at);

        $secondEntry->forceFill([
            'status' => PayrollEntry::STATUS_PAID,
            'paid_at' => '2026-04-25 12:30:00',
            'tx_signature' => 'second-paid-signature',
        ])->save();

        app(PayrollStatusService::class)->syncBatch($batch, '2026-04-25');

        $batch->refresh();
        $this->assertSame(PayrollBatch::STATUS_EXECUTED, $batch->status);
        $this->assertNotNull($batch->executed_at);
        $this->assertSame('2026-04-25 12:30:00', $batch->executed_at->format('Y-m-d H:i:s'));
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

    private function createEmployee(int $companyId, string $fullName, string $email, ?string $walletAddress = null): Employee
    {
        return Employee::query()->create([
            'company_id' => $companyId,
            'full_name' => $fullName,
            'email' => $email,
            'wallet_address' => $walletAddress,
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
