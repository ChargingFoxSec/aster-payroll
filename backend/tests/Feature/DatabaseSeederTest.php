<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_paid_employee_demo_accounts(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $alice = Employee::query()
            ->where('email', 'alice.payroll.demo@aster.test')
            ->firstOrFail();

        $this->assertSame('active', $alice->employment_status);
        $this->assertNotNull($alice->wallet_address);

        $this->assertDatabaseHas('users', [
            'email' => 'alice.payroll.demo@aster.test',
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $alice->id,
        ]);
        $this->assertDatabaseHas('compensation_amendments', [
            'employee_id' => $alice->id,
            'new_amount_minor' => 310000,
            'currency' => 'USDC',
        ]);

        $this->post(route('login.store'), [
            'email' => 'alice.payroll.demo@aster.test',
            'password' => 'password',
        ])
            ->assertRedirect(route('portal.show'));
    }
}
