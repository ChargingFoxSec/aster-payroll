<?php

use App\Services\Payroll\PayrollStatusService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payroll:sync-overdue {--date=}', function (PayrollStatusService $payrollStatusService): int {
    $date = $this->option('date');
    $syncedBatches = $payrollStatusService->syncOverdueBatches(
        is_string($date) && $date !== '' ? $date : null,
    );

    $this->info("Synced {$syncedBatches} payroll batch(es).");

    return Command::SUCCESS;
})->purpose('Mark unpaid payroll entries and batches as overdue when the due date passes.');

Artisan::command('payroll:demo-health', function (): int {
    $failed = false;
    $rpcUrl = (string) config('payroll.confidential.rpc_url');

    $check = function (string $label, bool $passed, string $detail = '') use (&$failed): void {
        $passed ? $this->info("PASS {$label}".($detail !== '' ? " - {$detail}" : '')) : $this->error("FAIL {$label}".($detail !== '' ? " - {$detail}" : ''));
        $failed = $failed || ! $passed;
    };

    $rpc = function (string $method, array $params = []) use ($rpcUrl): ?array {
        if ($rpcUrl === '') {
            return null;
        }

        $response = Http::timeout(3)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 'aster-payroll-demo-health',
            'method' => $method,
            'params' => $params,
        ]);

        return $response->ok() ? $response->json() : null;
    };

    $schemaChecks = [
        'payroll_batches table' => Schema::hasTable('payroll_batches'),
        'payout_executions table' => Schema::hasTable('payout_executions'),
        'payroll_entry_proofs table' => Schema::hasTable('payroll_entry_proofs'),
        'payout_executions.prepared_payload_hash column' => Schema::hasColumn('payout_executions', 'prepared_payload_hash'),
        'payout_executions.receipt_hash column' => Schema::hasColumn('payout_executions', 'receipt_hash'),
        'payroll_entry_proofs.proof_path column' => Schema::hasColumn('payroll_entry_proofs', 'proof_path'),
    ];
    $missingSchema = array_keys(array_filter($schemaChecks, fn (bool $passed): bool => ! $passed));

    $check(
        'Laravel DB migrations',
        $missingSchema === [],
        $missingSchema === [] ? '' : 'missing '.implode(', ', $missingSchema),
    );

    $anchorScript = (string) config('payroll.anchor.script');
    $check('Anchor bridge script', $anchorScript !== '' && is_file($anchorScript), $anchorScript);

    $anchorWallet = (string) config('payroll.anchor.wallet_path');
    $check('Anchor wallet path', $anchorWallet !== '' && is_file($anchorWallet), $anchorWallet !== '' ? $anchorWallet : 'ASTER_ANCHOR_WALLET is not set');

    $check('Configured Solana RPC', $rpcUrl !== '', $rpcUrl);

    try {
        $health = $rpc('getHealth');
        $validatorDetail = $rpcUrl.'; if this fails, run ./scripts/start-confidential-validator.sh from the repo root';
        $check('Local confidential validator reachability', is_array($health) && data_get($health, 'result') === 'ok', $validatorDetail);

        $tokenProgramId = (string) config('payroll.confidential.token_program_id');
        $check('Token-2022 program id', $tokenProgramId !== '', $tokenProgramId);
        $tokenProgram = $tokenProgramId !== '' ? $rpc('getAccountInfo', [$tokenProgramId, ['encoding' => 'base64']]) : null;
        $check('Token-2022 program availability', is_array(data_get($tokenProgram, 'result.value')), $tokenProgramId.'; restart the confidential validator helper if missing');

        $asterProgramId = (string) config('payroll.anchor.program_id');
        $check('Aster program id', $asterProgramId !== '', $asterProgramId);
        $asterProgram = $asterProgramId !== '' ? $rpc('getAccountInfo', [$asterProgramId, ['encoding' => 'base64']]) : null;
        $check('Aster program availability', is_array(data_get($asterProgram, 'result.value')), $asterProgramId.'; run NO_DNA=1 anchor build before starting the validator helper if missing');
    } catch (Throwable $throwable) {
        $check('Local confidential validator reachability', false, $throwable->getMessage());
    }

    return $failed ? Command::FAILURE : Command::SUCCESS;
})->purpose('Check the Level 3.5+ confidential payroll demo prerequisites.');

Schedule::command('payroll:sync-overdue')->dailyAt('01:00');
