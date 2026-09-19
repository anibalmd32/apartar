<?php

declare(strict_types=1);

use App\Http\Controllers\Backoffice\AuditLogController;
use App\Http\Controllers\Backoffice\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\Backoffice\Auth\LoginController;
use App\Http\Controllers\Backoffice\Auth\PasswordResetController;
use App\Http\Controllers\Backoffice\CategoryController;
use App\Http\Controllers\Backoffice\PaymentAccountController;
use App\Http\Controllers\Backoffice\SettingsController;
use App\Http\Controllers\Backoffice\TagController;
use App\Http\Controllers\Backoffice\TeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Backoffice del negocio ([05 §7.4], `REQ-BOF-001`).
|
| Dos grupos con superficies distintas, y la distinción no es formal:
|
|  - **acceso** (`outside-tenancy`): login y recuperación de contraseña
|    ocurren **antes** de saber de qué organización es quien escribe. No hay
|    tenant que resolver, y por eso `users.email` es único en toda la
|    plataforma (RB-2).
|  - **dentro** (`tenant`): a partir del guard, la organización se deriva del
|    usuario y `ResolveTenant` la fija. Ningún controlador escribe un `where`.
|
| El orden de los middlewares del segundo grupo es el de la cadena de
| REQ-BOF-001: tenant → persona activa → organización activa → contraseña al
| día. Cada uno da por hecho lo que resolvió el anterior.
|
| RB-11: nada de esto se enlaza desde el catálogo ni desde el portal.
*/

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
            /*
            | El panel sobrevive a la suspensión (RB-9): es donde se ve el
            | aviso y el enlace para regularizar. Bloquearlo dejaría al equipo
            | sin saber por qué no puede hacer nada.
            */
            Route::get('/', fn () => Inertia::render('backoffice/Dashboard'))
                ->middleware('during-suspension')
                ->name('dashboard');

            Route::post('logout', [LoginController::class, 'destroy'])
                ->middleware('during-suspension')
                ->name('logout');

            /*
            | El cambio obligatorio va dentro del guard y también sobrevive a
            | la suspensión: encerrar a alguien entre dos exigencias que se
            | bloquean mutuamente sería una trampa.
            */
            Route::get('cambiar-contrasena', [ForcedPasswordChangeController::class, 'edit'])
                ->middleware('during-suspension')
                ->name('password.change');

            Route::post('cambiar-contrasena', [ForcedPasswordChangeController::class, 'update'])
                ->middleware('during-suspension')
                ->name('password.change.store');

            /*
            | Taxonomía: categorías y etiquetas ([05 §7.4], `REQ-BOF-023`).
            |
            | **De los dos roles, y fuera del grupo del `owner`.** Categorías y
            | etiquetas son cómo se ordena el inventario, no cómo se gobierna
            | el negocio: `OrganizationCapability::Products` es de operación
            | diaria, y un empleado que carga productos y no puede crear la
            | categoría donde ponerlos tendría que pedírsela al dueño cada vez.
            |
            | **Ninguna declara `during-suspension`, y es la respuesta
            | correcta.** Lo que `REQ-ORG-008` RB-3 conserva durante una
            | suspensión es cobrar y aprobar comprobantes; reorganizar la
            | taxonomía del inventario no es ninguna de las dos cosas, y cuando
            | se regularice el pago vuelven todas a la vez. La omisión es la
            | declaración, por el fallo cerrado de `T-012`.
            |
            | `categorias/orden` va **antes** que `categorias/{category}` no
            | por necesidad —`PATCH` y `PUT` son verbos distintos— sino para
            | que se lea en el orden en que se usa, y para que siga siendo
            | correcta el día que alguien cambie uno de los dos verbos.
            |
            | El `{category}` y el `{tag}` se resuelven ya filtrados por el
            | tenant, así que el identificador de otra organización da `404` y
            | no `403` (SP-7, `REQ-ORG-002` RB-6): no se confirma que exista.
            */
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

            /*
            | Equipo (`REQ-ORG-004`, `REQ-ORG-005`). Del `owner`, por [05 §7.4]
            | y por `REQ-ORG-003` RB-1: quién entra al negocio es gobierno, no
            | operación diaria.
            |
            | **Ninguna declara `during-suspension`, y es la respuesta
            | correcta.** Lo que sobrevive a una suspensión es cobrar y aprobar
            | comprobantes (`REQ-ORG-008` RB-3); ampliar el equipo mientras se
            | le debe dinero a la plataforma es justo lo contrario de eso.
            | Reactivar y revocar tampoco pasan, y aquí la omisión no es
            | descuido sino el lado seguro del fallo cerrado: cuando se
            | regularice el pago vuelven todas a la vez.
            |
            | El `{member}` se resuelve **ya filtrado** por el tenant, así que
            | el identificador de otra organización da `404` y no `403`
            | (`REQ-ORG-002` RB-6): no se confirma que exista.
            */
            Route::middleware('owner')->group(function (): void {
                Route::get('equipo', [TeamController::class, 'index'])->name('team.index');
                Route::post('equipo', [TeamController::class, 'store'])->name('team.store');

                Route::post('equipo/{member}/reenviar', [TeamController::class, 'resend'])->name('team.resend');
                Route::delete('equipo/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

                Route::post('equipo/{member}/desactivar', [TeamController::class, 'disable'])->name('team.disable');
                Route::post('equipo/{member}/activar', [TeamController::class, 'enable'])->name('team.enable');

                /*
                | Cambio de rol (`REQ-ORG-003` RB-3, `T-013b`). Va con las
                | demás y no sobrevive a la suspensión por el mismo motivo:
                | repartir el gobierno del negocio no es cobrar ni aprobar
                | comprobantes, que es lo único que `REQ-ORG-008` RB-3
                | conserva.
                */
                Route::post('equipo/{member}/rol', [TeamController::class, 'role'])->name('team.role');

                /*
                | Ajustes del negocio (`REQ-ORG-006`, [05 §7.4]). Del `owner`,
                | por RB-1: tasa, plazos e identidad son gobierno.
                |
                | Dos guardados y una pantalla: los ajustes de operación y la
                | identidad son capacidades distintas de `REQ-ORG-003` RB-1
                | —`Settings` y `Branding`— con policies distintas, y juntarlas
                | obligaría a autorizar por la más permisiva de las dos.
                |
                | Tampoco sobreviven a la suspensión: configurar plazos no es
                | cobrar ni aprobar comprobantes, que es lo único que
                | `REQ-ORG-008` RB-3 conserva.
                */
                Route::get('ajustes', [SettingsController::class, 'edit'])->name('settings.edit');
                Route::put('ajustes', [SettingsController::class, 'update'])->name('settings.update');

                Route::put('ajustes/identidad', [SettingsController::class, 'updateIdentity'])
                    ->name('settings.identity');

                /*
                | Fuente de la tasa (`REQ-FX-003`, `T-021`). Tercer guardado de
                | la misma pantalla, y aparte por la misma razón que la
                | identidad: es una operación distinta —la fuente y la tasa
                | manual son un par que solo tiene sentido guardado junto
                | (SP-1)— con sus propios datos.
                |
                | La capacidad **sí** es la misma que la de los demás ajustes:
                | `OrganizationCapability::Settings` nombra la tasa
                | explícitamente, así que la policy es `updateSettings` y un
                | `member` recibe `403` (SP-3).
                |
                | Tampoco sobrevive a la suspensión, y aquí la tentación es
                | real: la tasa se ve en el catálogo. Pero elegir con qué tasa
                | se proyecta es gobierno del negocio, no cobrar ni aprobar
                | comprobantes, que es lo único que `REQ-ORG-008` RB-3
                | conserva; y la suspendida sigue proyectando con la fuente que
                | ya tenía elegida.
                */
                Route::put('ajustes/tasa', [SettingsController::class, 'updateRate'])
                    ->name('settings.rate');

                /*
                | Cuentas de cobro (`REQ-ORG-007`). Del `owner` por RB-1 de
                | `REQ-ORG-003`, y es la capacidad de gobierno más sensible que
                | hay: un empleado registra pagos y aprueba comprobantes, pero
                | **no decide a qué cuenta va el dinero**. Por eso no vive con
                | la cobranza.
                |
                | **Ninguna declara `during-suspension`, y es la respuesta
                | correcta.** `REQ-ORG-008` RB-2 conserva los datos de cobro
                | intactos durante una suspensión —los apartados vivos siguen
                | mostrando a dónde pagar—, que es distinto de poder cambiarlos:
                | lo que RB-3 deja abierto es cobrar y aprobar comprobantes, no
                | redirigir el dinero mientras se le debe a la plataforma. La
                | omisión es la declaración, por el fallo cerrado de `T-012`.
                |
                | El `{account}` se resuelve ya filtrado por el tenant, así que
                | el identificador de otra organización da `404` y no `403`
                | (`REQ-ORG-002` RB-6): no se confirma que exista.
                */
                Route::get('cuentas-de-cobro', [PaymentAccountController::class, 'index'])
                    ->name('payment-accounts.index');

                Route::post('cuentas-de-cobro', [PaymentAccountController::class, 'store'])
                    ->name('payment-accounts.store');

                Route::put('cuentas-de-cobro/{account}', [PaymentAccountController::class, 'update'])
                    ->name('payment-accounts.update');

                Route::delete('cuentas-de-cobro/{account}', [PaymentAccountController::class, 'destroy'])
                    ->name('payment-accounts.destroy');

                /*
                | Auditoría (`REQ-BOF-005` RB-9, SP-4). Del `owner`: el registro
                | contiene lo que hizo cada miembro del equipo, y dárselo a
                | quien aparece en él convertiría una herramienta de control en
                | una de vigilancia mutua.
                |
                | **Una sola ruta y solo `GET`.** Es `append-only` (RB-3): no
                | hay `PUT` ni `DELETE` porque no existe la operación, y lo que
                | sostiene la invariante es que no haya código que la haga, no
                | una comprobación que alguien pueda saltarse.
                |
                | Tampoco declara `during-suspension`. Lo que `REQ-ORG-008` RB-3
                | conserva es cobrar y aprobar comprobantes; consultar quién
                | cambió un precio en marzo puede esperar a que se regularice el
                | pago. La omisión es la declaración, por el fallo cerrado de
                | `T-012`.
                */
                Route::get('auditoria', [AuditLogController::class, 'index'])->name('audit.index');
            });
        });
});
