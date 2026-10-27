<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\AuditController;
use App\Http\Controllers\Platform\Auth\LoginController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\InvoiceController;
use App\Http\Controllers\Platform\OperatorController;
use App\Http\Controllers\Platform\OrganizationController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\SupportAccessController;
use App\Http\Controllers\Platform\SupportDataController;
use Illuminate\Support\Facades\Route;

Route::prefix('plataforma')->name('platform.')->middleware('without-tenant')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::middleware('platform-active')->group(function (): void {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('contrasena', [LoginController::class, 'editPassword'])->name('password.edit');
        Route::put('contrasena', [LoginController::class, 'updatePassword'])->name('password.update');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('salud', [DashboardController::class, 'health'])->name('health');

        Route::get('organizaciones', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizaciones/{organization}', [OrganizationController::class, 'show'])->withTrashed()->name('organizations.show');
        Route::post('organizaciones/{organization}/soporte', [OrganizationController::class, 'grantSupport'])->name('organizations.support.grant');
        Route::delete('organizaciones/{organization}/soporte/{grant}', [OrganizationController::class, 'revokeSupport'])->whereNumber('grant')->name('organizations.support.revoke');
        Route::post('organizaciones/{organization}/plan', [OrganizationController::class, 'overridePlan'])->name('organizations.plan');
        Route::post('organizaciones/{organization}/cortesia', [OrganizationController::class, 'courtesy'])->name('organizations.courtesy');
        Route::delete('organizaciones/{organization}/cortesia', [OrganizationController::class, 'revokeCourtesy'])->name('organizations.courtesy.revoke');
        Route::post('organizaciones/{organization}/facturas', [OrganizationController::class, 'issueInvoice'])->name('organizations.invoices.store');
        Route::post('organizaciones/{organization}/pausar', [OrganizationController::class, 'suspend'])->name('organizations.pause');
        Route::post('organizaciones/{organization}/reactivar', [OrganizationController::class, 'reactivate'])->name('organizations.reactivate');

        Route::middleware('support-access')->prefix('organizaciones/{organization}/datos')->name('support.')->group(function (): void {
            Route::get('apartados', [SupportDataController::class, 'layaways'])->name('layaways');
            Route::get('clientes', [SupportDataController::class, 'customers'])->name('customers');
        });

        Route::get('soporte', [SupportAccessController::class, 'index'])->name('support.index');
        Route::get('auditoria', [AuditController::class, 'index'])->name('audit.index');
        Route::get('ajustes', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('ajustes', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('operadores', [OperatorController::class, 'store'])->name('operators.store');
        Route::patch('operadores/{operator}/acceso', [OperatorController::class, 'access'])->whereNumber('operator')->name('operators.access');
        Route::post('operadores/{operator}/contrasena', [OperatorController::class, 'resetPassword'])->whereNumber('operator')->name('operators.password');

        Route::get('facturas', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('comprobantes/{receipt}/aprobar', [InvoiceController::class, 'approve'])->whereNumber('receipt')->name('receipts.approve');
        Route::post('comprobantes/{receipt}/rechazar', [InvoiceController::class, 'reject'])->whereNumber('receipt')->name('receipts.reject');
        Route::get('comprobantes/{receipt}/archivo', [InvoiceController::class, 'file'])->whereNumber('receipt')->name('receipts.file');
        Route::post('facturas/{invoice}/anular', [InvoiceController::class, 'void'])->whereNumber('invoice')->name('invoices.void');
    });
});
