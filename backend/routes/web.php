<?php

use App\Http\Controllers\ContractController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\PayrollDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');

Route::post('/employees/{employee}/contracts', [ContractController::class, 'store'])->name('employees.contracts.store');
Route::get('/contracts/{contract}/download', [ContractController::class, 'download'])->name('contracts.download');

Route::get('/payroll-demo', [PayrollDemoController::class, 'show'])->name('payroll-demo.show');
Route::post('/payroll-demo/run', [PayrollDemoController::class, 'run'])->name('payroll-demo.run');
