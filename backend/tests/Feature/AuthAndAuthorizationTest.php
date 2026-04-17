<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Payroll\ConfidentialPayrollService;
use App\Services\Payroll\PayrollReceiptImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_root_and_admin_pages(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->get(route('employees.index'))
            ->assertRedirect(route('login'));
    }

    public function test_web_responses_include_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_company_admin_login_redirects_to_dashboard(): void
    {
        $company = $this->demoCompany();
        $admin = $this->createCompanyAdmin($company, [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_employee_login_redirects_to_identity_derived_portal(): void
    {
        $employee = $this->createEmployeeRecord(null, [
            'email' => 'employee@example.com',
            'full_name' => 'Portal Employee',
        ]);
        $user = $this->createEmployeeUser($employee, [
            'password' => 'secret-password',
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertRedirect(route('portal.show'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->actingAsCompanyAdmin();

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_employee_cannot_access_admin_surfaces_or_download_contracts(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Portal User',
            'email' => 'portal@example.com',
        ]);
        $otherEmployee = $this->createEmployeeRecord($company, [
            'full_name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        Storage::disk('local')->put('contracts/demo.pdf', 'contract body');
        $contract = $otherEmployee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/demo.pdf',
            'file_hash' => hash('sha256', 'contract body'),
            'title' => 'Other User Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $this->actingAsEmployeeUser($employee, [
            'email' => 'portal@example.com',
        ]);

        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('employees.show', $otherEmployee))->assertForbidden();
        $this->get(route('payroll-batches.index'))->assertForbidden();
        $this->get(route('payroll-demo.show'))->assertForbidden();
        $this->post(route('payroll-demo.prepare'), [
            'employee_id' => $otherEmployee->id,
            'due_date' => '2026-04-30',
        ])->assertForbidden();
        $this->get(route('contracts.download', $contract))->assertForbidden();
    }

    public function test_employee_cannot_download_prepared_manifest(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $employee = $this->createEmployeeWithCompensation($company, [
            'full_name' => 'Portal User',
            'email' => 'portal@example.com',
        ], 225000);
        $execution = app(ConfidentialPayrollService::class)->prepareExecution($company, $employee, '2026-04-30');

        $this->actingAsEmployeeUser($employee, [
            'email' => 'portal@example.com',
        ]);

        $this->get(route('payroll-demo.executions.manifest', $execution))
            ->assertForbidden();
    }

    public function test_employee_self_service_pages_only_show_the_authenticated_employee(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Self User',
            'email' => 'self@example.com',
        ]);
        $otherEmployee = $this->createEmployeeRecord($company, [
            'full_name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/self.pdf',
            'file_hash' => str_repeat('a', 64),
            'title' => 'Self User Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        app(PayrollReceiptImportService::class)->importForEmployee(
            $company,
            $employee,
            $this->demoReceipt('SelfTransferSignature111111111111111111111111111111'),
            '2026-04-30',
        );
        app(PayrollReceiptImportService::class)->importForEmployee(
            $company,
            $otherEmployee,
            $this->demoReceipt('OtherTransferSignature11111111111111111111111111111'),
            '2026-04-30',
        );

        $this->actingAsEmployeeUser($employee, [
            'email' => 'self@example.com',
        ]);

        $this->get(route('portal.show'))
            ->assertOk()
            ->assertSee('Self User')
            ->assertSee('Self User Employment Contract')
            ->assertDontSee('Other User');

        $this->get(route('portal.payroll'))
            ->assertOk()
            ->assertSee('SelfTransferSignature111111111111111111111111111111')
            ->assertDontSee('OtherTransferSignature11111111111111111111111111111');
    }

    public function test_admin_can_download_contract_pdf(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Download User',
            'email' => 'download@example.com',
        ]);

        Storage::disk('local')->put('contracts/download.pdf', 'download body');
        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/download.pdf',
            'file_hash' => hash('sha256', 'download body'),
            'title' => 'Download User Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $this->get(route('contracts.download', $contract))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_employee_detail_page_does_not_render_private_contract_storage_paths(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Storage Safe',
            'email' => 'storage-safe@example.com',
        ]);

        $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/private/storage-safe-contract.pdf',
            'file_hash' => str_repeat('b', 64),
            'title' => 'Storage Safe Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $this->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Stored privately in Laravel storage.')
            ->assertDontSee('contracts/private/storage-safe-contract.pdf');
    }

    public function test_admin_cannot_import_a_payout_execution_from_another_company_scope(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $otherCompany = Company::query()->create([
            'name' => 'Other Demo Co',
            'slug' => 'other-demo-co',
            'wallet_address' => 'OtherDemoCompanyWallet1111111111111111111111111',
        ]);

        $this->actingAsCompanyAdmin($company, [
            'email' => 'admin@example.com',
        ]);

        $otherEmployee = $this->createEmployeeWithCompensation($otherCompany, [
            'full_name' => 'Other Scope User',
            'email' => 'other-scope@example.com',
        ], 190000);
        $execution = app(ConfidentialPayrollService::class)->prepareExecution($otherCompany, $otherEmployee, '2026-04-30');

        $receiptUpload = UploadedFile::fake()->createWithContent('receipt.json', '{}');

        $this->post(route('payroll-demo.import'), [
            'payout_execution_id' => $execution->id,
            'receipt' => $receiptUpload,
        ])
            ->assertSessionHasErrors('payout_execution_id');
    }

    private function demoReceipt(string $signature): array
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
                'confidential_transfer' => $signature,
            ],
        ];
    }

    private function createEmployeeWithCompensation(Company $company, array $attributes, int $amountMinor): Employee
    {
        $employee = $this->createEmployeeRecord($company, $attributes);

        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => "contracts/{$employee->id}-1.pdf",
            'file_hash' => str_pad((string) $employee->id, 64, '0', STR_PAD_LEFT),
            'title' => "{$employee->full_name} Employment Contract",
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => $amountMinor,
            'currency' => 'USDC',
            'effective_date' => '2026-04-01',
            'reason' => 'Fixture compensation',
        ]);

        return $employee;
    }
}
