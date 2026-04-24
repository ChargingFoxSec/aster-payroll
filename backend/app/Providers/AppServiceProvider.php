<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Policies\EmployeePolicy;
use App\Policies\EmploymentContractPolicy;
use App\Policies\PayoutExecutionPolicy;
use App\Policies\PayrollBatchPolicy;
use App\Services\Solana\PayrollAnchorClient;
use App\Services\Solana\ProcessPayrollAnchorClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PayrollAnchorClient::class, ProcessPayrollAnchorClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(EmploymentContract::class, EmploymentContractPolicy::class);
        Gate::policy(PayoutExecution::class, PayoutExecutionPolicy::class);
        Gate::policy(PayrollBatch::class, PayrollBatchPolicy::class);

        RateLimiter::for('login', function (Request $request): array {
            $email = strtolower((string) $request->string('email'));

            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(5)->by($email),
            ];
        });
    }
}
