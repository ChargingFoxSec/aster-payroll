<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_create_form_defaults_start_date_to_today(): void
    {
        $this->travelTo('2026-04-28 09:00:00');
        $this->actingAsCompanyAdmin();

        $this->get(route('employees.create'))
            ->assertOk()
            ->assertSee('name="start_date"', false)
            ->assertSee('value="2026-04-28"', false);
    }

    public function test_admin_can_create_an_employee(): void
    {
        $this->actingAsCompanyAdmin();

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
        $this->assertDatabaseMissing('users', [
            'email' => 'mina@example.com',
        ]);
    }

    public function test_employee_creation_rejects_a_non_demo_currency(): void
    {
        $this->actingAsCompanyAdmin();

        $response = $this->from(route('employees.create'))->post(route('employees.store'), [
            'full_name' => 'Mina Patel',
            'email' => 'mina@example.com',
            'wallet_address' => 'Wallet11111111111111111111111111111111111111',
            'employment_status' => 'active',
            'start_date' => '2026-04-10',
            'pay_cycle' => 'monthly',
            'currency' => 'EUR',
        ]);

        $response->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors(['currency']);

        $this->assertDatabaseMissing('employees', [
            'email' => 'mina@example.com',
        ]);
    }

    public function test_admin_can_create_an_employee_and_provision_portal_credentials(): void
    {
        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);

        $response = $this->post(route('employees.store'), [
            'full_name' => 'Mina Patel',
            'email' => 'mina@example.com',
            'wallet_address' => 'Wallet11111111111111111111111111111111111111',
            'employment_status' => 'active',
            'start_date' => '2026-04-10',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
            'provision_portal_account' => '1',
        ]);

        $employee = $company->employees()->where('email', 'mina@example.com')->firstOrFail();
        $portalUser = User::query()->where('email', 'mina@example.com')->firstOrFail();

        $response->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', __('ui.messages.employee_created_with_portal'))
            ->assertSessionHas('provisioned_portal_account', function (array $payload) use ($employee): bool {
                return $payload['employee_name'] === $employee->full_name
                    && $payload['email'] === 'mina@example.com'
                    && is_string($payload['temporary_password'])
                    && strlen($payload['temporary_password']) >= 16;
            });

        $this->assertSame(User::ROLE_EMPLOYEE, $portalUser->role);
        $this->assertSame($company->id, $portalUser->company_id);
        $this->assertSame($employee->id, $portalUser->employee_id);
    }

    public function test_portal_provisioning_requires_an_unused_login_email(): void
    {
        $this->actingAsCompanyAdmin();
        User::factory()->create([
            'email' => 'mina@example.com',
        ]);

        $response = $this->from(route('employees.create'))->post(route('employees.store'), [
            'full_name' => 'Mina Patel',
            'email' => 'mina@example.com',
            'wallet_address' => 'Wallet11111111111111111111111111111111111111',
            'employment_status' => 'active',
            'start_date' => '2026-04-10',
            'pay_cycle' => 'monthly',
            'currency' => 'USDC',
            'provision_portal_account' => '1',
        ]);

        $response->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors(['email']);

        $this->assertDatabaseMissing('employees', [
            'email' => 'mina@example.com',
        ]);
    }

    public function test_employee_create_validation_errors_are_localized_when_session_locale_is_chinese(): void
    {
        $this->actingAsCompanyAdmin();

        $this->withSession(['locale' => 'zh_CN'])
            ->from(route('employees.create'))
            ->post(route('employees.store'), [
                'full_name' => '',
                'email' => 'invalid-email',
                'employment_status' => 'active',
                'pay_cycle' => 'monthly',
                'currency' => 'EUR',
            ])
            ->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors([
                'full_name' => '请填写姓名。',
                'email' => '请输入有效的邮箱。',
                'currency' => '币种 必须是以下值之一：USDC。',
            ]);
    }

    public function test_admin_can_upload_and_hash_a_contract_pdf(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Rin Soto',
            'email' => 'rin@example.com',
            'start_date' => '2026-04-09',
        ]);

        $fileContents = 'Aster Payroll employment contract v1';
        $expectedHash = hash('sha256', $fileContents);

        $response = $this->post(route('employees.contracts.store', $employee), [
            'title' => 'Rin Soto Employment Contract',
            'effective_date' => '2026-04-13',
            'status' => 'active',
            'contract_pdf' => UploadedFile::fake()->createWithContent('contract.pdf', $fileContents),
        ]);

        $response->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Onchain contract anchoring is pending'));

        $this->assertDatabaseHas('contracts', [
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'version' => 1,
            'title' => 'Rin Soto Employment Contract',
            'file_hash' => $expectedHash,
        ]);

        $contract = $employee->contracts()->firstOrFail();
        Storage::disk('local')->assertExists($contract->file_path);
        $this->assertNull($contract->anchor_contract_pubkey);
        $this->assertSame([], $this->payrollAnchorClient()->calls);
    }

    public function test_contract_anchor_pending_warning_is_localized_when_session_locale_is_chinese(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Rin Soto',
            'email' => 'rin@example.com',
            'start_date' => '2026-04-09',
        ]);

        $this->withSession(['locale' => 'zh_CN'])
            ->post(route('employees.contracts.store', $employee), [
                'title' => 'Rin Soto Employment Contract',
                'effective_date' => '2026-04-13',
                'status' => 'active',
                'contract_pdf' => UploadedFile::fake()->createWithContent('contract.pdf', 'Aster Payroll employment contract v1'),
            ])
            ->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '合同 v1 已上传，哈希已生成。')
                && str_contains($status, '员工钱包地址和基础薪酬记录齐备后，系统才会执行链上合同锚定。')
                && ! str_contains($status, 'Onchain contract anchoring is pending'));
    }

    public function test_contract_upload_stays_saved_when_anchor_sync_returns_a_user_facing_error(): void
    {
        Storage::fake('local');

        $company = $this->demoCompany();
        $this->actingAsCompanyAdmin($company);
        $employee = $this->createEmployeeRecord($company, [
            'full_name' => 'Rin Soto',
            'email' => 'rin@example.com',
            'wallet_address' => 'RinWallet1111111111111111111111111111111111111',
            'start_date' => '2026-04-09',
        ]);
        $previousContract = $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => 1,
            'file_path' => 'contracts/rin-v1.pdf',
            'file_hash' => str_repeat('a', 64),
            'title' => 'Rin Soto Employment Contract v1',
            'effective_date' => '2026-04-01',
            'status' => 'active',
        ]);
        $employee->compensationAmendments()->create([
            'company_id' => $company->id,
            'contract_id' => $previousContract->id,
            'previous_amount_minor' => null,
            'new_amount_minor' => 250000,
            'currency' => 'USDC',
            'effective_date' => '2026-04-01',
            'reason' => 'Initial offer',
        ]);
        $this->bindThrowingPayrollAnchorClient(new UserFacingException('Onchain contract anchoring is temporarily unavailable.'));

        $response = $this->post(route('employees.contracts.store', $employee), [
            'title' => 'Rin Soto Employment Contract v2',
            'effective_date' => '2026-04-13',
            'status' => 'active',
            'contract_pdf' => UploadedFile::fake()->createWithContent('contract.pdf', 'Aster Payroll employment contract v2'),
        ]);

        $response->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, __('ui.messages.contract_uploaded', ['version' => 2]))
                && str_contains($status, 'Onchain contract anchoring is temporarily unavailable.'));

        $this->assertDatabaseHas('contracts', [
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'version' => 2,
            'title' => 'Rin Soto Employment Contract v2',
        ]);
    }
}
