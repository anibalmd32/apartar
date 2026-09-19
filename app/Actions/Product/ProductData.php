<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\FinancingPlan;
use App\Domain\Money\Money;

/**
 * Los datos de un producto, ya normalizados (`REQ-BOF-020`).
 *
 * Existe por lo mismo que `UpsertPaymentAccountData`: para que la acción
 * reciba datos y no un array suelto, y para que quien la construye —hoy los
 * dos `FormRequest`, mañana un seeder o una importación— decida de dónde salen
 * los valores sin que la acción tenga que volver a preguntárselo.
 *
 * ## Los importes entran como `Money` y no como cadenas
 *
 * RB-2: se escriben en dólares con dos decimales y se persisten en céntimos.
 * La conversión ocurre **en la frontera** —`Money::fromDecimalString()` en el
 * `FormRequest`— y no aquí, porque es donde el `422` de SP-8 tiene que
 * aparecer: «19.999» no se redondea en silencio, se rechaza junto a su campo.
 * A partir de este objeto ya no hay cadenas decimales dando vueltas.
 *
 * ## La taxonomía viaja en dos mitades, y son distintas
 *
 * `categoryId` y `tagIds` son lo que el negocio **eligió de su lista**;
 * `categoryName` y `tagNames` es lo que **escribió al vuelo** en el formulario.
 * Las dos mitades se resuelven de formas opuestas y por eso no se mezclan: un
 * identificador se busca dentro del tenant y un identificador ajeno es un
 * intento de cruzar la frontera (`REQ-ORG-002` RB-7), mientras que un nombre
 * que ya existe **se reutiliza** en lugar de fallar (`REQ-BOF-023` RB-8) y uno
 * nuevo crea la fila. Colapsarlas en un solo campo obligaría a adivinar cuál
 * de las dos cosas quiso decir quien lo envió.
 *
 * ## `slug` nulo significa «decídelo tú»
 *
 * Al crear, que se genere del nombre (RB-3). Al editar, que no se toque — y
 * eso es lo que hace verdadero que renombrar no arrastre el enlace, igual que
 * en la categoría. Pedir el cambio es explícito, y se cierra en cuanto el
 * producto está publicado (SP-5).
 *
 * ## Y `specifications` nulo significa «no la toques»
 *
 * Que no es lo mismo que la lista vacía, que **borra la ficha entera**
 * (`REQ-BOF-021` SP-3). La distinción existe porque el guardado de la ficha es
 * un reemplazo completo (RB-5): con un solo valor para las dos cosas, un
 * llamante que no gestione la ficha —un seeder, una importación— la borraría
 * sin pedirlo. El formulario del producto siempre manda la lista, aunque sea
 * con cero filas.
 */
final readonly class ProductData
{
    /**
     * @param  string  $name  nombre tal como lo escribió el negocio
     * @param  string  $sku  código del producto; único **por organización** (RB-3)
     * @param  string|null  $slug  enlace pedido; `null` lo genera al crear y lo conserva al editar
     * @param  string|null  $description  puede faltar en un borrador; publicar la exige (RB-5, `T-030`)
     * @param  Money  $price  precio de venta en USD
     * @param  Money|null  $compareAtPrice  precio tachado, mayor que el de venta
     * @param  Money  $downPayment  cuota inicial, estrictamente menor que el precio
     * @param  int  $installmentsCount  número de cuotas (1–36)
     * @param  int  $frequencyDays  días entre cuotas (1–90)
     * @param  int  $stock  existencias; cero **no** cambia el estado (`REQ-CAT-007` RB-4)
     * @param  int|null  $categoryId  categoría elegida de la lista de la organización
     * @param  string|null  $categoryName  categoría escrita al vuelo; se reutiliza si ya existe
     * @param  list<int>  $tagIds  etiquetas elegidas de la lista
     * @param  list<string>  $tagNames  etiquetas escritas al vuelo
     * @param  list<SpecificationInput>|null  $specifications  la ficha técnica completa; `null` no la toca
     */
    public function __construct(
        public string $name,
        public string $sku,
        public ?string $slug,
        public ?string $description,
        public Money $price,
        public ?Money $compareAtPrice,
        public Money $downPayment,
        public int $installmentsCount,
        public int $frequencyDays,
        public int $stock,
        public ?int $categoryId = null,
        public ?string $categoryName = null,
        public array $tagIds = [],
        public array $tagNames = [],
        public ?array $specifications = null,
    ) {}

    /**
     * El plan de pago que describen estos datos, para previsualizarlo (RB-7).
     *
     * Devuelve `null` cuando los términos no permiten repartir nada —la
     * inicial por encima del precio, o cero cuotas—, que es el estado normal
     * de un formulario a medio escribir. Las seis reglas de RB-1 las comprueba
     * `CoherentFinancingTerms` antes de guardar; esto solo evita que la
     * previsualización tenga que pintar un plan imposible.
     */
    public function financingPlan(): ?FinancingPlan
    {
        return FinancingPlan::tryFrom(
            $this->price,
            $this->downPayment,
            $this->installmentsCount,
            $this->frequencyDays,
        );
    }
}
