<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_executions', function (Blueprint $table): void {
            $table->char('prepared_payload_hash', 64)->nullable()->after('prepared_payload_path');
            $table->char('receipt_hash', 64)->nullable()->after('receipt_path');
            $table->timestamp('receipt_verified_at')->nullable()->after('imported_at');
        });
    }

    public function down(): void
    {
        Schema::table('payout_executions', function (Blueprint $table): void {
            $table->dropColumn([
                'prepared_payload_hash',
                'receipt_hash',
                'receipt_verified_at',
            ]);
        });
    }
};
