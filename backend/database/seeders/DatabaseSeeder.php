<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use App\Support\DemoCompany;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @return array<string, mixed>
     */
    private function factoryAttributes(User $user): array
    {
        return $user->makeVisible(['password', 'remember_token'])->toArray();
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = DemoCompany::resolve();

        User::query()->updateOrCreate(
            ['email' => 'admin@aster.test'],
            $this->factoryAttributes(
                User::factory()
                    ->companyAdmin($company)
                    ->make([
                        'email' => 'admin@aster.test',
                        'name' => 'Aster Admin',
                    ]),
                ),
        );

        foreach ($this->payrollDemoEmployees() as $demoEmployee) {
            $payrollEmployee = Employee::query()->updateOrCreate(
                ['company_id' => $company->id, 'email' => $demoEmployee['email']],
                [
                    'full_name' => $demoEmployee['name'],
                    'wallet_address' => $demoEmployee['wallet_address'],
                    'employment_status' => 'active',
                    'start_date' => '2026-05-01',
                    'pay_cycle' => 'monthly',
                    'currency' => 'USDC',
                ],
            );

            $contract = $payrollEmployee->contracts()->updateOrCreate(
                ['version' => 1],
                [
                    'company_id' => $company->id,
                    'file_path' => "contracts/{$company->id}/{$payrollEmployee->id}/payroll-demo-contract.pdf",
                    'file_hash' => hash('sha256', "payroll-demo-contract:{$payrollEmployee->email}:v1"),
                    'title' => "{$payrollEmployee->full_name} Employment Contract",
                    'effective_date' => '2026-05-01',
                    'status' => 'active',
                ],
            );

            $payrollEmployee->compensationAmendments()->updateOrCreate(
                ['effective_date' => '2026-05-01'],
                [
                    'company_id' => $company->id,
                    'contract_id' => $contract->id,
                    'previous_amount_minor' => null,
                    'new_amount_minor' => $demoEmployee['amount_minor'],
                    'currency' => 'USDC',
                    'reason' => 'Seeded payroll demo compensation',
                ],
            );

            User::query()->updateOrCreate(
                ['email' => $demoEmployee['email']],
                $this->factoryAttributes(
                    User::factory()
                        ->employee($payrollEmployee)
                        ->make([
                            'email' => $demoEmployee['email'],
                            'name' => $demoEmployee['name'],
                        ]),
                ),
            );
        }

        $employee = Employee::query()->firstOrCreate(
            ['company_id' => $company->id, 'email' => 'employee@aster.test'],
            [
                'full_name' => 'Aster Employee',
                'wallet_address' => null,
                'employment_status' => 'active',
                'start_date' => now()->startOfMonth()->toDateString(),
                'pay_cycle' => 'monthly',
                'currency' => 'USDC',
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'employee@aster.test'],
            $this->factoryAttributes(
                User::factory()
                    ->employee($employee)
                    ->make([
                        'email' => 'employee@aster.test',
                    ]),
                ),
        );
    }

    /**
     * @return array<int, array{name:string,email:string,wallet_address:string,amount_minor:int}>
     */
    private function payrollDemoEmployees(): array
    {
        return [
            [
                'name' => 'Alice Payroll',
                'email' => 'alice.payroll.demo@aster.test',
                'wallet_address' => 'Ft8iKnhhFdPWVcaUEywryzdYGjfSr7BpGKkjqDGgxnqw',
                'amount_minor' => 310000,
            ],
            [
                'name' => 'Bob Payroll',
                'email' => 'bob.payroll.demo@aster.test',
                'wallet_address' => 'A3ATLwAeV1kHHADBTNYZJ7mJ4B946CeFLeTMEQ4mdCBJ',
                'amount_minor' => 420000,
            ],
            [
                'name' => 'Carol Payroll',
                'email' => 'carol.payroll.demo@aster.test',
                'wallet_address' => '37LP9vpbRwgTjAJ9XM3u9BJG92sjhZ7RBhF89Gr1qCAD',
                'amount_minor' => 530000,
            ],
        ];
    }
}
