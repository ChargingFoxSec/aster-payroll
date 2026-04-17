<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CompensationAmendmentController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePayrollController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\PayrollBatchController;
use App\Http\Controllers\PayrollDemoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    $user = $request->user();

    if (! $user) {
        return redirect()->route('login');
    }

    return $user->isCompanyAdmin()
        ? redirect()->route('dashboard')
        : redirect()->route('portal.show');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'role:company_admin'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{employee}/payroll', [EmployeePayrollController::class, 'show'])->name('employees.payroll.show');

    Route::post('/employees/{employee}/contracts', [ContractController::class, 'store'])->name('employees.contracts.store');
    Route::post('/employees/{employee}/compensation-amendments', [CompensationAmendmentController::class, 'store'])->name('employees.compensation-amendments.store');
    Route::get('/contracts/{contract}/download', [ContractController::class, 'download'])->name('contracts.download');

    Route::get('/payroll-batches', [PayrollBatchController::class, 'index'])->name('payroll-batches.index');
    Route::post('/payroll-batches', [PayrollBatchController::class, 'store'])->name('payroll-batches.store');
    Route::get('/payroll-batches/{payrollBatch}', [PayrollBatchController::class, 'show'])->name('payroll-batches.show');

    Route::get('/payroll-demo', [PayrollDemoController::class, 'show'])->name('payroll-demo.show');
    Route::post('/payroll-demo/prepare', [PayrollDemoController::class, 'prepare'])->name('payroll-demo.prepare');
    Route::post('/payroll-demo/import', [PayrollDemoController::class, 'import'])->name('payroll-demo.import');
    Route::get('/payroll-demo/executions/{payoutExecution}/manifest', [PayrollDemoController::class, 'downloadManifest'])
        ->name('payroll-demo.executions.manifest');
});

Route::middleware(['auth', 'role:employee'])
    ->prefix('me')
    ->name('portal.')
    ->group(function (): void {
        Route::get('/', [EmployeePortalController::class, 'show'])->name('show');
        Route::get('/payroll', [EmployeePortalController::class, 'payroll'])->name('payroll');
    });
