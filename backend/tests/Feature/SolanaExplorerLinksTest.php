<?php

namespace Tests\Feature;

use App\Models\Attestation;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolanaExplorerLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_pages_link_transaction_signatures_to_local_solana_explorer(): void
    {
        config([
            'payroll.explorer.url' => 'https://explorer.solana.com',
            'payroll.explorer.browser_rpc_url' => 'http://localhost:8899/',
        ]);

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Explorer User',
            'email' => 'explorer@example.com',
        ]);
        $batch = PayrollBatch::query()->create([
            'company_id' => $company->id,
            'period_year' => 2027,
            'period_month' => 2,
            'status' => PayrollBatch::STATUS_EXECUTED,
            'total_amount_minor' => 1820000,
            'currency' => 'USDC',
            'due_date' => '2027-02-28',
            'entry_count' => 1,
            'entries_root' => str_repeat('1', 64),
            'approval_root' => str_repeat('2', 64),
            'settlement_root' => str_repeat('3', 64),
            'executed_at' => now(),
            'anchor_batch_pubkey' => 'AnchorBatchPubkey111111111111111111111111111111111111',
        ]);
        $entryTxSignature = 'EntryTransferSignature1111111111111111111111111111111';
        $executionTxSignature = 'ExecutionTransferSignature11111111111111111111111111';
        $finalizationTxSignature = 'FinalizeBatchSignature1111111111111111111111111111';
        $entry = PayrollEntry::query()->create([
            'payroll_batch_id' => $batch->id,
            'employee_id' => $employee->id,
            'amount_minor' => 1820000,
            'currency' => 'USDC',
            'status' => PayrollEntry::STATUS_PAID,
            'due_date' => '2027-02-28',
            'paid_at' => now(),
            'tx_signature' => $entryTxSignature,
        ]);

        PayoutExecution::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'payroll_entry_id' => $entry->id,
            'approval_method' => PayoutExecution::APPROVAL_METHOD_LOCAL_SIGNER,
            'status' => PayoutExecution::STATUS_IMPORTED,
            'prepared_payload_path' => 'payroll/prepared-payouts/explorer.json',
            'tx_signature' => $executionTxSignature,
            'imported_at' => now(),
            'receipt_verified_at' => now(),
        ]);
        Attestation::query()->create([
            'company_id' => $company->id,
            'payroll_batch_id' => $batch->id,
            'attestation_type' => 'payroll_batch_finalization',
            'tx_signature' => $finalizationTxSignature,
            'payload_hash' => str_repeat('f', 64),
            'issued_at' => now(),
        ]);

        $this->get(route('payroll-batches.index'))
            ->assertOk()
            ->assertSee($this->explorerTxUrl($finalizationTxSignature))
            ->assertSee('target="_blank"', false);

        $this->get(route('payroll-batches.show', $batch))
            ->assertOk()
            ->assertSee($this->explorerTxUrl($finalizationTxSignature))
            ->assertSee($this->explorerTxUrl($entryTxSignature));

        $this->get(route('payroll-demo.show', ['payroll_batch_id' => $batch->id]))
            ->assertOk()
            ->assertSee($this->explorerTxUrl($finalizationTxSignature))
            ->assertSee($this->explorerTxUrl($executionTxSignature));

        $this->get(route('employees.payroll.show', $employee))
            ->assertOk()
            ->assertSee($this->explorerTxUrl($entryTxSignature));
    }

    private function explorerTxUrl(string $signature): string
    {
        return 'https://explorer.solana.com/tx/'.$signature.'?cluster=custom&customUrl=http%3A%2F%2Flocalhost%3A8899%2F';
    }
}
