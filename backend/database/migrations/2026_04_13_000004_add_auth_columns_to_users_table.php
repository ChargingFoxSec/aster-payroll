<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 32)
                ->default(User::ROLE_COMPANY_ADMIN)
                ->after('password');
            $table->foreignId('company_id')
                ->nullable()
                ->after('email_verified_at')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('employee_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                ->nullOnDelete()
                ->unique();
        });

        $timestamp = now();
        $companyId = DB::table('companies')
            ->where('slug', config('payroll.demo_company.slug'))
            ->value('id');

        if (! $companyId) {
            $companyId = DB::table('companies')->insertGetId([
                'name' => config('payroll.demo_company.name'),
                'slug' => config('payroll.demo_company.slug'),
                'wallet_address' => config('payroll.demo_company.wallet_address'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        DB::table('users')
            ->whereNull('company_id')
            ->update(['company_id' => $companyId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_employee_id_unique');
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('role');
        });
    }
};
