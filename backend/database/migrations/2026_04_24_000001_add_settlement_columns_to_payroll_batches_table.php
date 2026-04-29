<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_batches', function (Blueprint $table): void {
            $table->unsignedInteger('entry_count')->default(0)->after('due_date');
            $table->char('entries_root', 64)->nullable()->after('entry_count');
            $table->char('approval_root', 64)->nullable()->after('entries_root');
            $table->char('settlement_root', 64)->nullable()->after('approval_root');
            $table->string('approved_by', 64)->nullable()->after('settlement_root');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('finalized_by', 64)->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'entry_count',
                'entries_root',
                'approval_root',
                'settlement_root',
                'approved_by',
                'approved_at',
                'finalized_by',
            ]);
        });
    }
};
