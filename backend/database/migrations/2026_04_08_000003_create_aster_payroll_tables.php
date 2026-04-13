<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('wallet_address', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('email');
            $table->string('wallet_address', 64)->nullable();
            $table->string('employment_status')->default('active');
            $table->date('start_date')->nullable();
            $table->string('pay_cycle', 32)->default('monthly');
            $table->string('currency', 8)->default('USDC');
            $table->timestamps();

            $table->unique(['company_id', 'email']);
            $table->unique(['company_id', 'wallet_address']);
            $table->index(['company_id', 'employment_status']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->string('title');
            $table->date('effective_date');
            $table->string('status', 32)->default('draft');
            $table->string('anchor_contract_pubkey', 64)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'version']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('compensation_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->unsignedBigInteger('previous_amount_minor')->nullable();
            $table->unsignedBigInteger('new_amount_minor');
            $table->string('currency', 8)->default('USDC');
            $table->date('effective_date');
            $table->string('reason')->nullable();
            $table->string('anchor_amendment_pubkey', 64)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
            $table->index(['company_id', 'effective_date']);
        });

        Schema::create('payroll_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('total_amount_minor')->default(0);
            $table->string('currency', 8)->default('USDC');
            $table->date('due_date');
            $table->timestamp('executed_at')->nullable();
            $table->string('anchor_batch_pubkey', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'period_year', 'period_month']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compensation_amendment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8)->default('USDC');
            $table->string('status', 32)->default('pending');
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->string('tx_signature', 128)->nullable();
            $table->timestamps();

            $table->unique(['payroll_batch_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('attestation_type', 64);
            $table->string('external_id')->nullable();
            $table->string('tx_signature', 128)->nullable();
            $table->char('payload_hash', 64);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'attestation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attestations');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_batches');
        Schema::dropIfExists('compensation_amendments');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('companies');
    }
};
