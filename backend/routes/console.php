<?php

use App\Services\Payroll\PayrollStatusService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
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

Schedule::command('payroll:sync-overdue')->dailyAt('01:00');
