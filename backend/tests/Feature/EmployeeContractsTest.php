<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Support\DemoCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_employee(): void
    {
        $response = $this->post(route('employees.store'), [
            'full_name' => 'Mina Patel',
            'email' => 'mina@example.com',
            'wallet_address' => 'Wallet11111111111111111111111111111111111111',
            'employment_status' => 'active',
            'start_date' => '2026-04-10',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'full_name' => 'Mina Patel',
            'email' => 'mina@example.com',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);
    }

    public function test_admin_can_upload_and_hash_a_contract_pdf(): void
    {
        Storage::fake('local');

        $company = DemoCompany::resolve();
        $employee = Employee::query()->create([
            'company_id' => $company->id,
            'full_name' => 'Rin Soto',
            'email' => 'rin@example.com',
            'wallet_address' => null,
            'employment_status' => 'active',
            'start_date' => '2026-04-09',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
        ]);

        $fileContents = 'Aster Payroll employment contract v1';
        $expectedHash = hash('sha256', $fileContents);

        $response = $this->post(route('employees.contracts.store', $employee), [
            'title' => 'Rin Soto Employment Contract',
            'effective_date' => '2026-04-13',
            'status' => 'active',
            'contract_pdf' => UploadedFile::fake()->createWithContent('contract.pdf', $fileContents),
        ]);

        $response->assertRedirect(route('employees.show', $employee));

        $this->assertDatabaseHas('contracts', [
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'version' => 1,
            'title' => 'Rin Soto Employment Contract',
            'file_hash' => $expectedHash,
        ]);

        $contract = $employee->contracts()->firstOrFail();
        Storage::disk('local')->assertExists($contract->file_path);
    }
}
