<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el negocio vende (`REQ-CAT-001`, `REQ-CAT-007`, `REQ-BOF-020`).
 *
 * Es la tabla central de H2 y la que más reglas sostiene. Tres cosas que no se
 * ven leyendo las columnas:
 *
 * ## El SKU y el slug son únicos **por organización**
 *
 * Y es lo contrario de `catalogs.slug`, que es único global, por el motivo
 * contrario: dos negocios distintos pueden vender el mismo modelo con el mismo
 * código de fábrica, y prohibirlo sería que el primero que llega se quede con
 * un código que no es suyo (`REQ-BOF-020` RB-3). El slug del catálogo sí es
 * global porque **es una URL pública** y dos negocios no pueden reclamar la
 * misma.
 *
 * ## El dinero va en céntimos enteros
 *
 * `price_cents`, `compare_at_price_cents` y `down_payment_cents` son `BIGINT`
 * de centavos de USD (`REQ-FX-001` RB-1), **con signo** como todo importe: la
 * resta de dos `BIGINT UNSIGNED` en MySQL no da negativo, desborda a un número
 * enorme, y con dinero eso es un balance corrupto en lugar de un error
 * visible ([05 §4]).
 *
 * ## El borrado es lógico y el estado no depende del stock
 *
 * `deleted_at` porque un producto con apartados **nunca** se borra
 * físicamente (`REQ-CAT-007` RB-2): los apartados vigentes operan sobre su
 * *snapshot* y el historial tiene que sobrevivir. Y `stock = 0` **no** cambia
 * el estado (RB-4): el producto sigue `published`, visible con su sello de
 * agotado y con el apartado deshabilitado, que es información para quien mira
 * y no un producto que desaparece del catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // **`restrict` y no `cascade`** (`REQ-BOF-023` RB-4): borrar una
            // categoría con productos dentro tiene que fallar, y la UI ofrece
            // reasignarlos antes. Con `cascade`, borrar una categoría se
            // llevaría por delante el inventario que cuelga de ella.
            //
            // Nullable porque un `draft` puede estar incompleto: la categoría
            // solo se exige para publicar (`REQ-BOF-020` RB-5).
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('sku', 64);
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->text('description')->nullable();

            // Centavos de USD, con signo. `compare_at_price_cents` es el
            // precio tachado y por eso es opcional.
            $table->bigInteger('price_cents');
            $table->bigInteger('compare_at_price_cents')->nullable();

            // Condiciones de financiamiento del producto (`REQ-BOF-020`
            // RB-1). Sus valores por defecto salen de `organization_settings`
            // (RB-4), no de aquí: el negocio los fija una vez y no los
            // reescribe en cada producto.
            $table->bigInteger('down_payment_cents')->default(0);
            $table->unsignedSmallInteger('installments_count')->default(1);
            $table->unsignedSmallInteger('frequency_days')->default(15);

            // Cantidad, no dinero: va `UNSIGNED` ([05 §4]).
            $table->unsignedInteger('stock')->default(0);

            // `ProductStatus`: draft | published | archived. Varchar y no
            // `ENUM` de MySQL ([02 §5]): añadir un caso no puede exigir una
            // migración de alteración. El enum llega con `T-025`.
            $table->string('status', 20)->default('draft');

            // Futuro es válido y actúa como publicación programada (RB-5); se
            // evalúa en la consulta, sin job.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'slug']);

            // El filtro que **siempre** está es el del tenant, así que va
            // delante: un índice `(status, organization_id)` serviría de poco.
            $table->index(['organization_id', 'status']);

            // El listado del backoffice filtra por categoría dentro de la
            // organización (`REQ-BOF-020` RB-9); la FK sola indexa
            // `category_id` a secas, que obliga a cruzar con el filtro del
            // tenant después.
            $table->index(['organization_id', 'category_id']);

            // **`FULLTEXT` y no un índice normal** (`REQ-CAT-002`). Va aquí y
            // no en `T-034` a propósito: añadirlo después obliga a reconstruir
            // el índice sobre una tabla ya cargada, y la búsqueda del catálogo
            // se apoya en él desde el primer día.
            //
            // Es de MySQL, y esta tabla lo necesita: la suite corre contra el
            // MySQL del contenedor precisamente por esto (`REQ-QAX-003` RB-8).
            // Un `LIKE %…%` no lo sustituye —no usa índice y no ordena por
            // relevancia—.
            $table->fullText(['name', 'description', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
