<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuotas del cronograma de un apartado (`REQ-LAY-004`).
 *
 * Nacen al **activar** el apartado, no al crearlo (RB-1), y son inmutables en
 * importe: solo `RescheduleInstallmentAction` mueve `due_date`, y nunca
 * `amount_cents` (RB-6, `REQ-LAY-011`).
 *
 * ## `due_date` es un día del calendario, no un instante
 *
 * Una cuota vence **el día 15 en la zona de la organización** (`REQ-ORG-006`
 * RB-7), no a una hora. Por eso es `DATE` y no `TIMESTAMP` ni `DATETIME`:
 *
 *  - un `TIMESTAMP` se guarda en UTC y se lee convirtiendo con la zona **de la
 *    sesión** de MySQL ([05 §4]), que es `APP_TIMEZONE` y no la del negocio;
 *    una cuota de un negocio en otra zona cambiaría de día según quién la
 *    lea;
 *  - un `DATETIME` no convierte, pero lleva una hora que no significa nada, y
 *    esa hora es la que acaba decidiendo si «vence hoy» según a qué hora del
 *    servidor se pregunte.
 *
 * La consecuencia para **«vencida»** es que es una comparación de días, no de
 * instantes: una cuota está vencida cuando `due_date + grace_period_days` es
 * **anterior a hoy en la zona de la organización** (`REQ-LAY-007` RB-1). Ese
 * «hoy» lo calcula PHP con la zona del negocio y se pasa como parámetro;
 * **nunca** `CURDATE()` ni `NOW()` de MySQL, que responden en la zona de la
 * sesión y darían a un negocio de otra zona un día de más o de menos.
 *
 * ## El único es `(organization_id, layaway_id, number)`
 *
 * [05 §4] lo declaraba `UNIQUE(layaway_id, number)`, sin la columna del
 * tenant, y eso choca con `REQ-ORG-002` RB-3 —**todo** índice único de negocio
 * incluye `organization_id`, salvo las tres excepciones— y con `tenancy:audit`,
 * que lo rechaza. La salida **no** es una cuarta entrada en
 * `GlobalUniqueExceptions`: esa lista es de claves que se resuelven sin tenant,
 * y una cuota nunca se busca sin él. Es anteponer la columna. La unicidad que
 * protege es la misma: mientras cada cuota lleve la organización de su
 * apartado —que es lo que garantizan la guarda de creación de
 * `BelongsToOrganization` y `REQ-ORG-002` RB-7—, `(layaway_id, number)` y
 * `(organization_id, layaway_id, number)` admiten exactamente las mismas
 * filas. Y sigue siendo la que hace idempotente el reintento de la activación
 * (`REQ-LAY-004` SP-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // **`cascade`**: una cuota es parte de su apartado, no una entidad
            // con vida propia, y un apartado no se borra en ningún flujo de
            // negocio. Lo que protegerá el historial de cobro son las claves
            // de `payment_allocations` (`T-054`), no esta.
            $table->foreignId('layaway_id')->constrained()->cascadeOnDelete();

            // 1…`installments_count`, y este sí va `UNSIGNED`: no es dinero.
            $table->unsignedSmallInteger('number');

            // Ver el bloque de arriba.
            $table->date('due_date');

            // Centavos de USD con signo. El residuo del reparto cae en la
            // última cuota (RB-2): `sum(amount_cents)` es exactamente el
            // financiado (RB-5).
            $table->bigInteger('amount_cents');
            $table->bigInteger('paid_cents')->default(0);

            // `InstallmentStatus` (`T-042`): pending | partially_paid | paid |
            // overdue. Varchar y no `ENUM` de MySQL ([02 §5]).
            $table->string('status', 20)->default('pending');

            // Cuándo quedó saldada: un instante, a diferencia de `due_date`.
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // Ver el bloque de arriba.
            $table->unique(['organization_id', 'layaway_id', 'number']);

            // El barrido de mora (`REQ-LAY-007`): «las cuotas no pagadas de
            // este negocio que vencieron antes de tal día».
            $table->index(['organization_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
