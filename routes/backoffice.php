<?php

declare(strict_types=1);

use App\Http\Controllers\Backoffice\AuditLogController;
use App\Http\Controllers\Backoffice\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\Backoffice\Auth\LoginController;
use App\Http\Controllers\Backoffice\Auth\PasswordResetController;
use App\Http\Controllers\Backoffice\CatalogController;
use App\Http\Controllers\Backoffice\CategoryController;
use App\Http\Controllers\Backoffice\CollectionController;
use App\Http\Controllers\Backoffice\CustomerController;
use App\Http\Controllers\Backoffice\CustomerMergeController;
use App\Http\Controllers\Backoffice\CustomerShowController;
use App\Http\Controllers\Backoffice\DashboardController;
use App\Http\Controllers\Backoffice\InstallmentController;
use App\Http\Controllers\Backoffice\LayawayCodeController;
use App\Http\Controllers\Backoffice\LayawayController;
use App\Http\Controllers\Backoffice\ManualPaymentController;
use App\Http\Controllers\Backoffice\PaymentAccountController;
use App\Http\Controllers\Backoffice\PaymentReallocationController;
use App\Http\Controllers\Backoffice\ProductController;
use App\Http\Controllers\Backoffice\ProductImageController;
use App\Http\Controllers\Backoffice\ReceiptInboxController;
use App\Http\Controllers\Backoffice\ReceiptReviewController;
use App\Http\Controllers\Backoffice\SettingsController;
use App\Http\Controllers\Backoffice\TagController;
use App\Http\Controllers\Backoffice\TeamController;
use App\Http\Controllers\ReceiptFileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
            Route::get('/', DashboardController::class)
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
            | Productos ([05 §7.4], `REQ-BOF-020`).
            |
            | **De los dos roles**, como el resto del inventario: cargar
            | productos es operación diaria y no gobierno del negocio
            | (`OrganizationCapability::Products`).
            |
            | **Ninguna declara `during-suspension`, y es exactamente lo que
            | RB-11 pide**: con la organización suspendida no se crean ni se
            | editan productos. Lo que `REQ-ORG-008` RB-3 conserva es cobrar y
            | aprobar comprobantes; el inventario espera a que se regularice el
            | pago. Aquí la omisión no es descuido sino el fallo cerrado de
            | `T-012`, y esta vez además coincide con una regla escrita.
            |
            | `productos/crear` va **antes** que `productos/{product}/editar`
            | por costumbre de lectura: los dos son `GET` y no compiten, porque
            | el segundo lleva un segmento más.
            |
            | El `{product}` se resuelve ya filtrado por el tenant, así que el
            | identificador de otra organización da `404` y no `403` (SP-11,
            | `REQ-ORG-002` RB-6): no se confirma que exista.
            */
            Route::get('productos', [ProductController::class, 'index'])->name('products.index');
            Route::get('productos/crear', [ProductController::class, 'create'])->name('products.create');
            Route::post('productos', [ProductController::class, 'store'])->name('products.store');

            Route::get('productos/{product}/editar', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('productos/{product}', [ProductController::class, 'update'])->name('products.update');

            /*
            | Estados de publicación (`REQ-CAT-007`).
            |
            | Las cuatro transiciones de la matriz, una ruta por botón:
            | publicar —con la lista de completitud de `REQ-BOF-020` RB-5
            | delante—, despublicar, restaurar y archivar. No hay una quinta
            | que lleve a `published` desde el archivo: SP-4 dice que restaurar
            | devuelve a **borrador**, y la matriz de la acción lo niega aunque
            | alguien componga la petición a mano.
            |
            | **Ninguna declara `during-suspension`**, como el resto del
            | inventario: publicar es mover el escaparate, y con la
            | organización suspendida el escaparate espera a que se regularice
            | el pago (RB-11, `REQ-ORG-008` RB-3).
            |
            | `publicar` admite un `published_at` opcional: futuro es
            | publicación programada (RB-5), evaluada en la consulta y sin job.
            |
            | El `{product}` llega ya filtrado por el tenant, así que el de
            | otra organización da `404` (SP-11).
            */
            Route::post('productos/{product}/publicar', [ProductController::class, 'publish'])
                ->name('products.publish');

            Route::post('productos/{product}/despublicar', [ProductController::class, 'unpublish'])
                ->name('products.unpublish');

            Route::post('productos/{product}/restaurar', [ProductController::class, 'restore'])
                ->name('products.restore');

            /*
            | RB-10 · el borrado es siempre lógico y se llama «archivar». El
            | producto conserva su fila, su historial y su sitio en los
            | catálogos (`REQ-CAT-007` RB-7, SP-10).
            */
            Route::post('productos/{product}/archivar', [ProductController::class, 'archive'])
                ->name('products.archive');

            /*
            | Imágenes de producto ([05 §7.4], `REQ-BOF-022`).
            |
            | Las tres del contrato y ninguna más: subir, borrar y guardar el
            | orden. El texto alternativo viaja con el orden (RB-6) porque es
            | lo que la galería edita en el mismo guardado y porque una cuarta
            | ruta no está en el contrato.
            |
            | **Ninguna declara `during-suspension`**, como el resto del
            | inventario: `REQ-ORG-008` RB-3 conserva cobrar y aprobar
            | comprobantes, no cargar fotos. La omisión es la declaración, por
            | el fallo cerrado de `T-012`.
            |
            | `imagenes/orden` va antes que `imagenes/{media}` por costumbre de
            | lectura: son verbos distintos y no compiten.
            |
            | El `{product}` llega ya filtrado por el tenant → `404` para el
            | ajeno (SP-9). El `{media}` **no**: su tabla está declarada fuera
            | del filtro por organización ([05 §4]), así que quien comprueba
            | que la imagen es de este producto es el controlador.
            */
            Route::post('productos/{product}/imagenes', [ProductImageController::class, 'store'])
                ->name('products.images.store');

            Route::patch('productos/{product}/imagenes/orden', [ProductImageController::class, 'order'])
                ->name('products.images.order');

            /*
            | El `{media}` es un número y **no** un modelo enlazado, a
            | propósito: `media` está fuera del filtro por organización, así
            | que un binding implícito resolvería la fila del negocio de al
            | lado y dejaría la comprobación de pertenencia en manos del
            | controlador de todas formas. Pidiéndolo como identificador, esa
            | comprobación es el único camino y no hay una resolución previa
            | que pueda confundirse con una autorización.
            */
            Route::delete('productos/{product}/imagenes/{media}', [ProductImageController::class, 'destroy'])
                ->whereNumber('media')
                ->name('products.images.destroy');

            /*
            | Catálogos ([05 §7.4], `REQ-BOF-024`, `REQ-CAT-022`,
            | `REQ-CAT-023`).
            |
            | Las seis del contrato, ya completas: el CRUD (`T-032`), la
            | composición (`T-031`) y la publicación (`T-032`). Siguen siendo
            | **tres escrituras separadas hacia tres grupos de columnas
            | distintos**, y eso no es reparto de tareas sino la regla:
            | `catalog_product` tiene un solo escritor desde `T-024`, y
            | `published_at`, `is_listed` y `expires_at` tienen el suyo desde
            | esta tarea. Un `PUT` que lo guardara todo sería el segundo camino
            | que se olvida de los seis requisitos de `REQ-CAT-022` RB-4.
            |
            | `catalogos/crear` va **antes** que `catalogos/{catalog}/editar`
            | por costumbre de lectura: los dos son `GET` y no compiten, porque
            | el segundo lleva un segmento más. Las dos pantallas no están en
            | el contrato de `REQ-BOF-024`, que enumera los verbos de
            | escritura; un CRUD con formulario las necesita, y son las mismas
            | dos que `REQ-BOF-020` sí declara para el producto.
            |
            | **`POST` y `DELETE` sobre el mismo `publicar`**: publicar y
            | despublicar son el mismo recurso —la publicación del catálogo—
            | creado y borrado, y así lo declara el contrato. Publicar admite
            | además la visibilidad y la caducidad, porque las tres columnas se
            | escriben juntas o no se escriben.
            |
            | **De los dos roles**, como el resto del inventario: armar un
            | catálogo es operación diaria (`OrganizationCapability::Products`),
            | y un empleado que carga productos y no puede ponerlos en el
            | escaparate tendría que pedírselo al dueño cada vez.
            |
            | **Ninguna declara `during-suspension`**, como el inventario
            | entero: lo que `REQ-ORG-008` RB-3 conserva durante una suspensión
            | es cobrar y aprobar comprobantes, y mover el escaparate no es
            | ninguna de las dos (RB-11). La omisión es la declaración, por el
            | fallo cerrado de `T-012`, y en `publicar` coincide además con una
            | regla escrita: `REQ-CAT-022` SP-9 pide `403`.
            |
            | **Despublicar tampoco la declara, y eso sí se pensó.** Retirar
            | algo de la calle no es crear nada, así que dejarlo abierto sería
            | defendible; se queda cerrado porque la lista de excepciones de
            | `REQ-ORG-008` RB-3 es cerrada —cobrar y aprobar comprobantes— y
            | ampliarla por un caso razonable es cómo esa lista deja de
            | significar nada. La campaña de un negocio suspendido ya no se ve:
            | sus catálogos están despublicados por RB-2.
            |
            | El `{catalog}` llega ya filtrado por el tenant → `404` para el
            | ajeno (`REQ-BOF-024` SP-10). Los **productos** de la lista no los
            | puede comprobar el binding, y esa guarda es de la acción
            | (`REQ-ORG-002` RB-7): es lo que sostiene que el pivote no lleve
            | `organization_id`.
            */
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
            /*
            | Fusionar fichas (`REQ-IDN-006`, `T-065`): de los dos roles
            | (`CustomerPolicy::merge`), y **fuera de `during-suspension`**:
            | reescribir las fichas de clientes no es cobrar ni aprobar
            | (`REQ-ORG-008` RB-3), y puede esperar a que se regularice el
            | pago.
            */
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

            /*
            | Apartados (`REQ-LAY-009`, `REQ-LAY-010`, `REQ-LAY-011`). De los
            | dos roles: la cobranza es operación diaria, y quién puede lo dice
            | `LayawayPolicy` en cada acción.
            |
            | **Todas declaran `during-suspension`.** Cancelar, entregar y
            | reprogramar son la gestión de la cobranza que `REQ-SUB-007` RB-4
            | preserva (`REQ-LAY-009` RB-9): con la organización suspendida
            | los apartados vivos siguen su curso, y el negocio tiene que poder
            | cerrar los abandonados, entregar lo pagado y pactar fechas.
            |
            | El apartado viaja por su **código** (`{layaway:code}`), que es
            | como el negocio y el cliente lo nombran. Apartado y cuota se
            | resuelven ya filtrados por el tenant: los de otra organización
            | dan `404` y no `403` (`REQ-ORG-002` RB-6).
            |
            | La vista del apartado es mínima a propósito (`T-053`): los
            | listados de cobranza que llevan a ella son de `T-065`.
            */
            Route::middleware('during-suspension')->group(function (): void {
                // La cartera (`REQ-BOF-042`, `T-065`): lectura de la cobranza,
                // que sobrevive a la suspensión (RB-9).
                Route::get('cobranza', CollectionController::class)->name('collections.index');

                /*
                | Clientes (`REQ-BOF-040`, `REQ-BOF-041`, `T-065`): el
                | directorio y el perfil, en lectura, que es cobrar. `{customer}`
                | llega filtrado por el tenant —la de otro negocio, `404`—; una
                | ficha absorbida por una fusión ya no existe, y el `missing()`
                | de la ruta la lleva a la superviviente (SP-3).
                | Reenviar el código de un apartado también es cobrar.
                */
                Route::get('clientes', [CustomerController::class, 'index'])->name('customers.index');
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

                /*
                | El pago registrado a mano (`REQ-PAY-007`, `T-062`), desde la
                | vista del apartado. Sobrevive a la suspensión (RB-8): cobrar es
                | lo que se conserva. La vista previa es un `GET` sin efectos,
                | como la de la aprobación.
                */
                Route::post('apartados/{layaway:code}/pagos', [ManualPaymentController::class, 'store'])->name('layaways.payments.store');
                Route::get('apartados/{layaway:code}/pagos/vista-previa', [ManualPaymentController::class, 'preview'])->name('layaways.payments.preview');

                /*
                | Reasignar un pago entre las cuotas de su apartado
                | (`REQ-PAY-009`, `T-063`). Es gestión de la cobranza y sobrevive
                | a la suspensión. `{payment}` es numérico y llega filtrado por
                | el tenant: el de otro negocio es `404` (SP-2).
                */
                Route::patch('pagos/{payment}/reasignar', PaymentReallocationController::class)
                    ->whereNumber('payment')
                    ->name('payments.reallocate');

                /*
                | El archivo del comprobante (`REQ-PAY-002`), para el equipo.
                | Sobrevive a la suspensión: sin verlo no se aprueba, y aprobar
                | comprobantes es lo que `REQ-ORG-008` RB-3 conserva.
                |
                | Firmada (`SignedFileUrl::forBackoffice`, 15 minutos) además de
                | la sesión, el tenant y `ReceiptPolicy`. `signed` corre detrás
                | del binding, así que el `{receipt}` de otro negocio es `404`
                | aunque la URL esté bien firmada. La del cliente está en
                | `portal.php`; el porqué de dos rutas, en el controlador.
                */
                Route::get('comprobantes/{receipt}/archivo', ReceiptFileController::class)
                    ->middleware('signed')
                    ->name('receipts.file');

                /*
                | La bandeja de conciliación (`REQ-PAY-004`) y su contador.
                | Sobreviven a la suspensión por RB-9: aprobar comprobantes es
                | justo lo que `REQ-ORG-008` RB-3 no deja bloquear por impago.
                |
                | `comprobantes/pendientes` es la cifra del badge, que la
                | pantalla sondea cada 30 s mientras no haya tiempo real (SP-1).
                | Va **antes** de cualquier `comprobantes/{receipt}` que llegue
                | (la revisión de `T-061`), para que «pendientes» no se lea como
                | un identificador.
                */
                Route::get('comprobantes', ReceiptInboxController::class)->name('receipts.index');
                Route::get('comprobantes/pendientes', [ReceiptInboxController::class, 'pending'])->name('receipts.pending');

                /*
                | La revisión y la aprobación (`REQ-PAY-004` RB-6, `REQ-PAY-005`,
                | `T-061`). Sobreviven a la suspensión (RB-12): aprobar es lo que
                | no se bloquea por impago. `{receipt}` es numérico, así que
                | «pendientes» no se lee nunca como un comprobante, se declare
                | en el orden que se declare; y llega filtrado por el tenant: el
                | de otra organización es `404` (SP-11).
                |
                | La vista previa es un `GET` sin efectos —aplica y deshace—, y
                | la pantalla la pide al editar el importe o la tasa.
                */
                Route::get('comprobantes/{receipt}', [ReceiptReviewController::class, 'show'])
                    ->whereNumber('receipt')
                    ->name('receipts.show');

                Route::get('comprobantes/{receipt}/vista-previa', [ReceiptReviewController::class, 'preview'])
                    ->whereNumber('receipt')
                    ->name('receipts.preview');

                Route::post('comprobantes/{receipt}/aprobar', [ReceiptReviewController::class, 'approve'])
                    ->whereNumber('receipt')
                    ->name('receipts.approve');

                // `REQ-PAY-006`, `T-062`: rechazar, con su código y su texto.
                Route::post('comprobantes/{receipt}/rechazar', [ReceiptReviewController::class, 'reject'])
                    ->whereNumber('receipt')
                    ->name('receipts.reject');
            });

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
