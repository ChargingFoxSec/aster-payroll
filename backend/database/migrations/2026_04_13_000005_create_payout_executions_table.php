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
        Schema::create('payout_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->string('approval_method', 32)->default('local_signer');
            $table->string('status', 32)->default('awaiting_approval');
            $table->string('prepared_payload_path');
            $table->string('receipt_path')->nullable();
            $table->string('approved_wallet_address', 64)->nullable();
            $table->string('tx_signature', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique('payroll_entry_id');
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_executions');
    }
};
