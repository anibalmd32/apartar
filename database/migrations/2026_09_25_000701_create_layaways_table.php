<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El apartado: la operación central del producto (`REQ-LAY-001`,
 * `REQ-LAY-002`, `REQ-LAY-005`).
 *
 * La fila tiene tres partes, y cada una tiene un dueño distinto:
 *
 * ## `code` es único en **toda la plataforma**, y es la tercera de las tres
 *
 * Es la credencial del portal (`REQ-IDN-008` RB-3): con documento + código se
 * entra sin haber elegido negocio, y de este código se resuelve la
 * organización **sin tenant previo**. Por eso el índice es `UNIQUE(code)` a
 * secas y está declarado en `GlobalUniqueExceptions`. Es justo lo que no
 * necesita el enlace por correo de `customer_login_tokens`, que ya sabe a qué
 * organización pertenece y por eso lleva un índice compuesto.
 *
 * ## El snapshot se escribe una vez y no se vuelve a tocar
 *
 * `unit_price_cents`, `down_payment_cents`, `quantity`, `installments_count`,
 * `frequency_days`, `total_cents` y los dos `*_snapshot` son las condiciones
 * **congeladas** al crear (`REQ-LAY-002` RB-1): a partir de ahí el apartado no
 * vuelve a leer `products` para nada financiero, y ninguna acción las
 * modifica (RB-8). `product_id` y `catalog_id` quedan para navegar, no para
 * calcular (RB-6, RB-7).
 *
 * ## El balance está materializado, y tiene **un solo** escritor
 *
 * `paid_cents`, `remaining_cents`, `next_due_date` y `status` son columnas y
 * no agregados (`REQ-PAY-008` RB-1): las lecturas no suman pagos.
 *
 * **`remaining_cents` es `total_cents − paid_cents` escrito otra vez**, a
 * propósito: la cartera y las exportaciones se ordenan y se filtran por él.
 * Pero es un segundo censo del mismo dato, y el día que discrepe de la resta
 * nadie sabrá cuál es el bueno. Así que tiene dueño: **solo
 * `LayawayBalanceRecalculator` lo escribe** (`REQ-PAY-008` RB-7, `T-057`),
 * dentro de la transacción de cada pago de H4, y `layaways:reconcile` es quien
 * comprueba que sigue siendo la resta (RB-3). La única otra escritura es la
 * del `INSERT`: `CreateLayawayAction` (`T-045`) lo siembra igual a
 * `total_cents`, con `paid_cents` a cero. **Nadie más lo calcula**: ni una
 * acción, ni un accesor, ni el frontend. Quien necesite el saldo lo lee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layaways', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // `AP-` + 8 caracteres en base32 sin `0`, `O`, `1` ni `I`
            // (`REQ-LAY-001` RB-9): 11 caracteres en su forma canónica, que
            // decide `LayawayCode` (`T-045`). La colación *case-insensitive*
            // por defecto es la que se quiere: `ap-7k3m…` y `AP-7K3M…` son el
            // mismo código.
            $table->string('code', 16);

            // **`restrict`**: una ficha con apartados no se borra. Es lo que
            // hace fallar íntegra la fusión de fichas si la absorbida conserva
            // filas dependientes (`REQ-IDN-006` RB-5).
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // **`restrict`** (`REQ-LAY-002` RB-6, SP-3): el producto no se
            // borra físicamente mientras tenga apartados, ni siquiera por
            // acceso directo a la base. El borrado de negocio es lógico
            // (`products.deleted_at`, `REQ-CAT-007` RB-2).
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Catálogo de origen, **informativo** (RB-7). Nullable y
            // `nullOnDelete` porque SP-4 lo pide así: si el catálogo
            // desaparece, el apartado sigue intacto y solo deja de enlazar a
            // él. Con `cascade` borrar un catálogo se llevaría los apartados;
            // con `restrict` un catálogo con ventas no se podría borrar nunca.
            $table->foreignId('catalog_id')->nullable()->constrained()->nullOnDelete();

            // --- Snapshot de condiciones (`REQ-LAY-002`) -----------------

            // Entre 1 y `min(stock, 10)` (`REQ-LAY-001` RB-5). Cantidad, no
            // dinero: va `UNSIGNED` ([05 §4]).
            $table->unsignedSmallInteger('quantity');

            // Centavos de USD con signo, como todo importe ([05 §4]).
            //
            // **Los dos son por unidad**: `total = unit_price × quantity` y la
            // inicial total es `down_payment × quantity` (RB-2). Guardar aquí
            // la inicial ya multiplicada sería la forma de cobrarla dos veces.
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('down_payment_cents');

            // **No** se multiplican por la cantidad (RB-3). Rangos de
            // `REQ-LAY-004` RB-7: cuotas 1–36, frecuencia 1–90 días.
            $table->unsignedSmallInteger('installments_count');
            $table->unsignedSmallInteger('frequency_days');

            // Legibles aunque el producto se renombre o se archive (RB-5).
            // Mismos anchos que `products.name` y `products.sku`.
            $table->string('product_name_snapshot', 200);
            $table->string('product_sku_snapshot', 64);

            // `unit_price_cents × quantity`, fijado al crear.
            $table->bigInteger('total_cents');

            // --- Balance materializado (`REQ-PAY-008`) --------------------
            // Un solo escritor: ver el bloque de arriba.

            $table->bigInteger('paid_cents')->default(0);

            // **Sin valor por defecto**: el único correcto sería `total_cents`,
            // y un `DEFAULT 0` diría que el apartado ya está pagado.
            $table->bigInteger('remaining_cents');

            // `LayawayStatus` (`T-042`). Varchar y no `ENUM` de MySQL
            // ([02 §5]). Todo apartado nace en `pending_down_payment`
            // (`REQ-LAY-001` RB-7).
            $table->string('status', 30)->default('pending_down_payment');

            // --- Ciclo de vida (`REQ-LAY-005`) ----------------------------
            // Instantes, no días: van `TIMESTAMP`.

            // Ventana de la inicial, `now() + down_payment_window_hours`
            // (`REQ-LAY-001` RB-7). Nula cuando ya no aplica.
            $table->timestamp('expires_at')->nullable();

            // La prórroga de 24 h, **una sola vez** (`REQ-LAY-006` RB-3): que
            // esté fijada es lo que impide la segunda.
            $table->timestamp('extended_at')->nullable();

            $table->timestamp('activated_at')->nullable();

            // **`DATE` y no `TIMESTAMP`**, por lo mismo que
            // `installments.due_date`: es el día de la primera cuota no
            // pagada, un día del calendario de la organización y no un
            // instante (`REQ-LAY-004` RB-8).
            $table->date('next_due_date')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Obligatorio al cancelar, de 10 a 500 caracteres
            // (`REQ-LAY-009` RB-2); el mínimo lo valida la acción.
            $table->string('cancellation_reason', 500)->nullable();

            // De un solo sentido: una vez fijado no se limpia
            // (`REQ-LAY-010` RB-2).
            $table->timestamp('delivered_at')->nullable();

            // Hace idempotente la liberación del stock (`REQ-LAY-003` SP-3):
            // un job reintentado ve que ya se devolvió y no suma dos veces.
            $table->timestamp('stock_released_at')->nullable();

            $table->timestamps();

            // La excepción de [05 §4.1]: sin `organization_id` a propósito.
            $table->unique('code');

            // El barrido de mora y la cartera del backoffice: «los activos de
            // este negocio ordenados por su próximo vencimiento».
            $table->index(['organization_id', 'status', 'next_due_date']);

            // Los apartados de una ficha dentro de su negocio: el portal
            // acotado (`REQ-IDN-008` RB-4) y el perfil del cliente.
            $table->index(['organization_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layaways');
    }
};
