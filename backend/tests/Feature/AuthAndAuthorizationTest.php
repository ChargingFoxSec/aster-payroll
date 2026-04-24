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
        $contentSecurityPolicy = $response->headers->get('Content-Security-Policy');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->assertNotNull($contentSecurityPolicy);
        $this->assertStringNotContainsString('localhost:5173', (string) $contentSecurityPolicy);
        $this->assertStringNotContainsString("'unsafe-eval'", (string) $contentSecurityPolicy);
    }

    public function test_guest_login_page_shows_language_switcher_without_header_login_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Language')
            ->assertSee('English')
            ->assertDontSee('href="'.route('login').'"', false);
    }

    public function test_locale_switch_changes_rendered_ui_language(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $this->post(route('locale.update'), [
            'locale' => 'zh_CN',
        ])
            ->assertRedirect()
            ->assertSessionHas('locale', 'zh_CN');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('仪表盘')
            ->assertSee('语言')
            ->assertSee('当前页面');
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

    public function test_login_errors_are_localized_when_session_locale_is_chinese(): void
    {
        $this->withSession(['locale' => 'zh_CN'])
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'missing@example.com',
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'email' => __('ui.messages.login_invalid_credentials', [], 'zh_CN'),
            ]);
    }

    public function test_dashboard_distinguishes_active_employees_from_total_records(): void
    {
        $company = $this->demoCompany();
        $this->createEmployeeRecord($company, [
            'full_name' => 'Active Demo User',
            'email' => 'active-demo@example.com',
            'employment_status' => 'active',
        ]);
        $this->createEmployeeRecord($company, [
            'full_name' => 'Paused Demo User',
            'email' => 'paused-demo@example.com',
            'employment_status' => 'paused',
        ]);

        $this->actingAsCompanyAdmin($company);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Active Employees')
            ->assertSee('1')
            ->assertSee('2 total records');
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

        $batchEmployee = $this->createEmployeeWithCompensation($company, [
            'full_name' => 'Batch Scope User',
            'email' => 'batch-scope@example.com',
        ], 225000);
        $batch = app(ConfidentialPayrollService::class)->prepareExecution($company, $batchEmployee, '2026-04-30')
            ->payrollEntry
            ->payrollBatch;

        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('employees.show', $otherEmployee))->assertForbidden();
        $this->get(route('payroll-batches.index'))->assertForbidden();
        $this->get(route('payroll-demo.show'))->assertForbidden();
        $this->post(route('payroll-demo.prepare'), [
            'payroll_batch_id' => $batch->id,
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

    public function test_employee_portal_uses_the_latest_effective_compensation_instead_of_a_future_amendment(): void
    {
        $company = $this->demoCompany();
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Future Raise User',
            'email' => 'future-raise@example.com',
        ]);

        $contract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/future-raise.pdf',
            'file_hash' => str_repeat('c', 64),
            'title' => 'Future Raise Employment Contract',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);

        $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => 250000,
            'currency' => 'USDC',
            'effective_date' => '2026-04-01',
            'reason' => 'Current salary',
        ]);
        $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'previous_amount_minor' => 250000,
            'new_amount_minor' => 300000,
            'currency' => 'USDC',
            'effective_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'reason' => 'Future raise',
        ]);

        $this->actingAsEmployeeUser($employee, [
            'email' => 'future-raise@example.com',
        ]);

        $this->get(route('portal.show'))
            ->assertOk()
            ->assertSee('2,500.00 USDC')
            ->assertDontSee('3,000.00 USDC');
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

    public function test_dashboard_only_uses_receipts_imported_for_the_authenticated_company(): void
    {
        $company = $this->demoCompany();
        $otherCompany = Company::query()->create([
            'name' => 'Other Demo Co',
            'slug' => 'other-demo-co-dashboard',
            'wallet_address' => 'OtherDemoCoDashboardWallet1111111111111111111111',
        ]);

        $this->actingAsCompanyAdmin($company);

        $otherEmployee = $this->createEmployeeWithCompensation($otherCompany, [
            'full_name' => 'Other Dashboard User',
            'email' => 'other-dashboard@example.com',
        ], 190000);
        $execution = app(ConfidentialPayrollService::class)->prepareExecution($otherCompany, $otherEmployee, '2026-04-30');

        app(PayrollReceiptImportService::class)->importForExecution($execution, [
            'generated_at' => '2026-04-30T10:15:00Z',
            'approval' => [
                'approving_wallet_address' => $otherCompany->wallet_address,
            ],
            'payroll' => [
                'amount_minor' => 190000,
            ],
            'transactions' => [
                'confidential_transfer' => 'OtherCompanyDashboardTransferSignature11111111111',
            ],
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No payout execution has been prepared yet.')
            ->assertDontSee('OtherCompanyDashboardTransferSignature11111111111');
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
