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
}
