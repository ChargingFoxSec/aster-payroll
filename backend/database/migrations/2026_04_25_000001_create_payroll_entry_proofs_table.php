<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_entry_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('proof_version', 64);
            $table->char('employee_ref_hash', 64);
            $table->char('compensation_ref_hash', 64)->nullable();
            $table->char('amount_commitment_hash', 64);
            $table->char('amount_nonce', 64);
            $table->char('leaf_hash', 64);
            $table->json('leaf_payload');
            $table->timestamps();

            $table->unique('payroll_entry_id');
            $table->unique(['payroll_batch_id', 'position']);
            $table->index(['payroll_batch_id', 'leaf_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entry_proofs');
    }
};
