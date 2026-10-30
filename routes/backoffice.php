<?php

declare(strict_types=1);

use App\Http\Controllers\Backoffice\AnalyticsController;
use App\Http\Controllers\Backoffice\AuditLogController;
use App\Http\Controllers\Backoffice\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\Backoffice\Auth\LoginController;
use App\Http\Controllers\Backoffice\Auth\PasswordResetController;
use App\Http\Controllers\Backoffice\CatalogController;
use App\Http\Controllers\Backoffice\CategoryController;
use App\Http\Controllers\Backoffice\ChatController;
use App\Http\Controllers\Backoffice\CollectionController;
use App\Http\Controllers\Backoffice\CustomerController;
use App\Http\Controllers\Backoffice\CustomerMergeController;
use App\Http\Controllers\Backoffice\CustomerShowController;
use App\Http\Controllers\Backoffice\DashboardController;
use App\Http\Controllers\Backoffice\ExportController;
use App\Http\Controllers\Backoffice\GlobalSearchController;
use App\Http\Controllers\Backoffice\InstallmentController;
use App\Http\Controllers\Backoffice\LayawayCodeController;
use App\Http\Controllers\Backoffice\LayawayController;
use App\Http\Controllers\Backoffice\LayawayCreationController;
use App\Http\Controllers\Backoffice\ManualPaymentController;
use App\Http\Controllers\Backoffice\ManualPushController;
use App\Http\Controllers\Backoffice\OrganizationClosureController;
use App\Http\Controllers\Backoffice\OrganizationFoundingController;
use App\Http\Controllers\Backoffice\OrganizationSwitchController;
use App\Http\Controllers\Backoffice\PaymentAccountController;
use App\Http\Controllers\Backoffice\PaymentReallocationController;
use App\Http\Controllers\Backoffice\ProductController;
use App\Http\Controllers\Backoffice\ProductCopyController;
use App\Http\Controllers\Backoffice\ProductImageController;
use App\Http\Controllers\Backoffice\ProfileController;
use App\Http\Controllers\Backoffice\ReceiptInboxController;
use App\Http\Controllers\Backoffice\ReceiptReviewController;
use App\Http\Controllers\Backoffice\SettingsController;
use App\Http\Controllers\Backoffice\SubscriberController;
use App\Http\Controllers\Backoffice\SubscriptionController;
use App\Http\Controllers\Backoffice\SubscriptionInvoiceController;
use App\Http\Controllers\Backoffice\TagController;
use App\Http\Controllers\Backoffice\TeamController;
use App\Http\Controllers\PaymentFileController;
use App\Http\Controllers\ReceiptFileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('backoffice')->group(function (): void {
    Route::middleware(['guest', 'outside-tenancy'])->group(function (): void {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store']);

        Route::get('recuperar', [PasswordResetController::class, 'create'])->name('password.request');
        Route::post('recuperar', [PasswordResetController::class, 'store'])->name('password.email');

        Route::get('restablecer/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('restablecer', [PasswordResetController::class, 'update'])->name('password.store');
    });

    Route::middleware(['auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'])
        ->group(function (): void {
            Route::get('/', DashboardController::class)
                ->middleware('during-suspension')
                ->name('dashboard');

            Route::post('negocios/{organization}/entrar', OrganizationSwitchController::class)
                ->middleware(['during-suspension', 'throttle:30,1'])
                ->name('organizations.switch');

            Route::get('negocios/nuevo', [OrganizationFoundingController::class, 'create'])
                ->middleware('during-suspension')
                ->name('organizations.create');

            Route::get('negocios/enlace', [OrganizationFoundingController::class, 'slug'])
                ->middleware(['during-suspension', 'throttle:registro-enlace'])
                ->name('organizations.slug');

            Route::post('negocios', [OrganizationFoundingController::class, 'store'])
                ->middleware(['during-suspension', 'throttle:registro'])
                ->name('organizations.store');

            Route::get('buscar', GlobalSearchController::class)
                ->middleware(['during-suspension', 'throttle:60,1'])
                ->name('search');

            Route::get('suscripcion', [SubscriptionController::class, 'show'])
                ->middleware('during-suspension')
                ->name('subscription.show');

            Route::post('suscripcion/cambiar', [SubscriptionController::class, 'change'])
                ->middleware('during-suspension')
                ->name('subscription.change');

            Route::get('suscripcion/facturas', [SubscriptionInvoiceController::class, 'index'])
                ->middleware('during-suspension')
                ->name('subscription.invoices.index');

            Route::post('suscripcion/facturas/{invoice}/comprobante', [SubscriptionInvoiceController::class, 'storeReceipt'])
                ->middleware(['during-suspension', 'throttle:20,60'])
                ->name('subscription.invoices.receipt');

            Route::post('suscripcion/cerrar-negocio', [OrganizationClosureController::class, 'store'])
                ->middleware(['during-suspension', 'throttle:5,60'])
                ->name('organization.close');

            Route::get('avisos', [ManualPushController::class, 'create'])->name('notifications.create');
            Route::get('avisos/clientes', [ManualPushController::class, 'recipients'])
                ->middleware('throttle:60,1')
                ->name('notifications.recipients');
            Route::post('avisos/vista-previa', [ManualPushController::class, 'preview'])
                ->middleware(['plan-feature:web_push', 'throttle:60,1'])
                ->name('notifications.preview');
            Route::post('avisos', [ManualPushController::class, 'store'])
                ->middleware(['plan-feature:web_push', 'throttle:avisos'])
                ->name('notifications.send');

            Route::get('exportaciones', [ExportController::class, 'index'])
                ->middleware('during-suspension')
                ->name('exports.index');

            Route::post('exportaciones', [ExportController::class, 'store'])
                ->middleware(['plan-feature:excel_export', 'throttle:30,60'])
                ->name('exports.store');

            Route::get('exportaciones/{export}/descargar', [ExportController::class, 'download'])
                ->middleware(['during-suspension', 'signed'])
                ->whereNumber('export')
                ->name('exports.download');

            Route::post('logout', [LoginController::class, 'destroy'])
                ->middleware('during-suspension')
                ->name('logout');

            Route::get('perfil', [ProfileController::class, 'edit'])
                ->middleware('during-suspension')
                ->name('profile.edit');

            Route::patch('perfil', [ProfileController::class, 'update'])
                ->middleware(['during-suspension', 'throttle:10,1'])
                ->name('profile.update');

            Route::put('perfil/contrasena', [ProfileController::class, 'updatePassword'])
                ->middleware(['during-suspension', 'throttle:6,1'])
                ->name('profile.password');

            Route::get('cambiar-contrasena', [ForcedPasswordChangeController::class, 'edit'])
                ->middleware('during-suspension')
                ->name('password.change');

            Route::post('cambiar-contrasena', [ForcedPasswordChangeController::class, 'update'])
                ->middleware('during-suspension')
                ->name('password.change.store');

            Route::get('productos', [ProductController::class, 'index'])->name('products.index');
            Route::get('productos/crear', [ProductController::class, 'create'])->name('products.create');
            Route::post('productos', [ProductController::class, 'store'])->name('products.store');

            Route::post('productos/publicar-varios', [ProductController::class, 'publishMany'])
                ->name('products.publish-many');

            Route::post('productos/ia/sugerencia', ProductCopyController::class)
                ->middleware('throttle:20,1')
                ->name('products.ai.suggest');

            Route::get('productos/{product}/editar', [ProductController::class, 'edit'])->name('products.edit');
            Route::get('productos/{product}/vista-previa', [ProductController::class, 'preview'])->name('products.preview');
            Route::put('productos/{product}', [ProductController::class, 'update'])->name('products.update');

            Route::post('productos/{product}/publicar', [ProductController::class, 'publish'])
                ->name('products.publish');

            Route::post('productos/{product}/despublicar', [ProductController::class, 'unpublish'])
                ->name('products.unpublish');

            Route::post('productos/{product}/restaurar', [ProductController::class, 'restore'])
                ->name('products.restore');

            Route::post('productos/{product}/archivar', [ProductController::class, 'archive'])
                ->name('products.archive');

            Route::post('productos/{product}/imagenes', [ProductImageController::class, 'store'])
                ->name('products.images.store');

            Route::patch('productos/{product}/imagenes/orden', [ProductImageController::class, 'order'])
                ->name('products.images.order');

            Route::delete('productos/{product}/imagenes/{media}', [ProductImageController::class, 'destroy'])
                ->whereNumber('media')
                ->name('products.images.destroy');

            Route::get('catalogos', [CatalogController::class, 'index'])->name('catalogs.index');
            Route::get('catalogos/crear', [CatalogController::class, 'create'])->name('catalogs.create');
            Route::post('catalogos', [CatalogController::class, 'store'])->name('catalogs.store');

            Route::get('catalogos/{catalog}/editar', [CatalogController::class, 'edit'])->name('catalogs.edit');
            Route::put('catalogos/{catalog}', [CatalogController::class, 'update'])->name('catalogs.update');

            Route::patch('catalogos/{catalog}/productos', [CatalogController::class, 'products'])
                ->name('catalogs.products');

            Route::post('catalogos/{catalog}/publicar', [CatalogController::class, 'publish'])
                ->name('catalogs.publish');

            Route::delete('catalogos/{catalog}/publicar', [CatalogController::class, 'unpublish'])
                ->name('catalogs.unpublish');

            Route::post('clientes/{customer}/fusionar', [CustomerMergeController::class, 'store'])->whereNumber('customer')->name('customers.merge');

            Route::get('categorias', [CategoryController::class, 'index'])->name('categories.index');
            Route::post('categorias', [CategoryController::class, 'store'])->name('categories.store');

            Route::patch('categorias/orden', [CategoryController::class, 'reorder'])->name('categories.reorder');

            Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

            Route::post('categorias/{category}/reasignar', [CategoryController::class, 'reassign'])
                ->name('categories.reassign');

            Route::get('etiquetas', [TagController::class, 'index'])->name('tags.index');
            Route::post('etiquetas', [TagController::class, 'store'])->name('tags.store');
            Route::put('etiquetas/{tag}', [TagController::class, 'update'])->name('tags.update');
            Route::delete('etiquetas/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

            Route::get('apartados/nuevo', [LayawayCreationController::class, 'create'])->name('layaways.create');
            Route::get('apartados/nuevo/clientes', [LayawayCreationController::class, 'customers'])
                ->middleware('throttle:60,1')
                ->name('layaways.create.customers');
            Route::get('apartados/nuevo/vista-previa-pago', [LayawayCreationController::class, 'paymentPreview'])
                ->middleware('throttle:60,1')
                ->name('layaways.create.payment-preview');
            Route::post('apartados', [LayawayCreationController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('layaways.store');

            Route::middleware('during-suspension')->group(function (): void {
                Route::get('cobranza', CollectionController::class)->name('collections.index');

                Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
                Route::get('chat/{conversation}', [ChatController::class, 'show'])->whereNumber('conversation')->name('chat.show');
                Route::post('chat/{conversation}', [ChatController::class, 'store'])->whereNumber('conversation')->name('chat.store');
                Route::get('chat/{conversation}/mensajes', [ChatController::class, 'messages'])->whereNumber('conversation')->name('chat.messages');
                Route::post('chat/{conversation}/escribiendo', [ChatController::class, 'typing'])->whereNumber('conversation')->middleware('throttle:60,1')->name('chat.typing');

                Route::get('clientes', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('suscriptores', [SubscriberController::class, 'index'])->name('subscribers.index');
                Route::get('analitica', AnalyticsController::class)->name('analytics.index');
                Route::get('clientes/{customer}', CustomerShowController::class)
                    ->whereNumber('customer')
                    ->missing(fn (Request $request) => CustomerShowController::redirectMerged($request))
                    ->name('customers.show');
                Route::get('clientes/{customer}/fusionar/candidatas', [CustomerMergeController::class, 'candidates'])->whereNumber('customer')->name('customers.merge.candidates');
                Route::post('apartados/{layaway:code}/reenviar-codigo', LayawayCodeController::class)
                    ->middleware('throttle:reenvio-codigo')
                    ->name('layaways.resend-code');
                Route::get('apartados/{layaway:code}', [LayawayController::class, 'show'])->name('layaways.show');
                Route::post('apartados/{layaway:code}/cancelar', [LayawayController::class, 'cancel'])->name('layaways.cancel');
                Route::post('apartados/{layaway:code}/entregar', [LayawayController::class, 'deliver'])->name('layaways.deliver');
                Route::patch('cuotas/{installment}/reprogramar', [InstallmentController::class, 'reschedule'])->name('installments.reschedule');

                Route::post('apartados/{layaway:code}/pagos', [ManualPaymentController::class, 'store'])->name('layaways.payments.store');
                Route::get('apartados/{layaway:code}/pagos/vista-previa', [ManualPaymentController::class, 'preview'])->name('layaways.payments.preview');

                Route::patch('pagos/{payment}/reasignar', PaymentReallocationController::class)
                    ->whereNumber('payment')
                    ->name('payments.reallocate');

                Route::get('comprobantes/{receipt}/archivo', ReceiptFileController::class)
                    ->middleware('signed')
                    ->name('receipts.file');

                Route::get('pagos/{payment}/archivo', PaymentFileController::class)
                    ->whereNumber('payment')
                    ->middleware('signed')
                    ->name('payments.file');

                Route::get('comprobantes', ReceiptInboxController::class)->name('receipts.index');
                Route::get('comprobantes/pendientes', [ReceiptInboxController::class, 'pending'])->name('receipts.pending');

                Route::get('comprobantes/{receipt}', [ReceiptReviewController::class, 'show'])
                    ->whereNumber('receipt')
                    ->name('receipts.show');

                Route::get('comprobantes/{receipt}/vista-previa', [ReceiptReviewController::class, 'preview'])
                    ->whereNumber('receipt')
                    ->name('receipts.preview');

                Route::post('comprobantes/{receipt}/aprobar', [ReceiptReviewController::class, 'approve'])
                    ->whereNumber('receipt')
                    ->name('receipts.approve');

                Route::post('comprobantes/{receipt}/rechazar', [ReceiptReviewController::class, 'reject'])
                    ->whereNumber('receipt')
                    ->name('receipts.reject');
            });

            Route::middleware('owner')->group(function (): void {
                Route::get('equipo', [TeamController::class, 'index'])->name('team.index');
                Route::post('equipo', [TeamController::class, 'store'])->name('team.store');

                Route::post('equipo/{member}/reenviar', [TeamController::class, 'resend'])->name('team.resend');
                Route::delete('equipo/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

                Route::post('equipo/{member}/desactivar', [TeamController::class, 'disable'])->name('team.disable');
                Route::post('equipo/{member}/activar', [TeamController::class, 'enable'])->name('team.enable');

                Route::post('equipo/{member}/rol', [TeamController::class, 'role'])->name('team.role');

                Route::get('ajustes', [SettingsController::class, 'edit'])->name('settings.edit');
                Route::put('ajustes', [SettingsController::class, 'update'])->name('settings.update');

                Route::put('ajustes/identidad', [SettingsController::class, 'updateIdentity'])
                    ->name('settings.identity');

                Route::put('ajustes/tasa', [SettingsController::class, 'updateRate'])
                    ->name('settings.rate');

                Route::get('cuentas-de-cobro', [PaymentAccountController::class, 'index'])
                    ->name('payment-accounts.index');

                Route::post('cuentas-de-cobro', [PaymentAccountController::class, 'store'])
                    ->name('payment-accounts.store');

                Route::put('cuentas-de-cobro/{account}', [PaymentAccountController::class, 'update'])
                    ->name('payment-accounts.update');

                Route::delete('cuentas-de-cobro/{account}', [PaymentAccountController::class, 'destroy'])
                    ->name('payment-accounts.destroy');

                Route::get('auditoria', [AuditLogController::class, 'index'])->name('audit.index');
            });
        });
});
