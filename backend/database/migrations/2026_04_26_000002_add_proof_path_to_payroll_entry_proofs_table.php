<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entry_proofs', function (Blueprint $table): void {
            $table->json('proof_path')->nullable()->after('leaf_payload');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entry_proofs', function (Blueprint $table): void {
            $table->dropColumn('proof_path');
        });
    }
};
