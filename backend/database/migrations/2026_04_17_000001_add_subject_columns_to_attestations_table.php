<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attestations', function (Blueprint $table): void {
            $table->foreignId('compensation_amendment_id')
                ->nullable()
                ->after('contract_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('payroll_batch_id')
                ->nullable()
                ->after('compensation_amendment_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['compensation_amendment_id', 'attestation_type']);
            $table->index(['payroll_batch_id', 'attestation_type']);
        });
    }

    public function down(): void
    {
        Schema::table('attestations', function (Blueprint $table): void {
            $table->dropIndex(['compensation_amendment_id', 'attestation_type']);
            $table->dropIndex(['payroll_batch_id', 'attestation_type']);
            $table->dropConstrainedForeignId('payroll_batch_id');
            $table->dropConstrainedForeignId('compensation_amendment_id');
        });
    }
};
