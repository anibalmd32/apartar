<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * En qué punto de su vida está un producto (`REQ-CAT-007` RB-1).
 *
 * Tres estados y ninguno más: se prepara en borrador, se publica, y se retira
 * archivándolo. **Archivar no es borrar** (RB-2): un producto con apartados
 * nunca desaparece de la base, porque los apartados vigentes operan sobre su
 * *snapshot* y el historial tiene que poder leerse. Por eso el estado que la
 * UI llama «Archivar» conserva la fila, y por eso `products` lleva además
 * `deleted_at`.
 *
 * ## Lo que estos dos métodos **no** contestan
 *
 * La visibilidad real de un producto es una **intersección** (RB-6): su estado
 * y la accesibilidad del catálogo que lo contiene. Un producto puede estar
 * `published` y no verlo nadie porque sus catálogos están en borrador, y el
 * backoffice lo dice con esas palabras («publicado, en 0 catálogos»).
 * `isVisibleInCatalog()` responde **solo por el estado**, y su nombre no
 * significa que sepa en qué catálogo está: quien cruza las dos mitades es la
 * consulta pública (`REQ-CAT-021` RB-4).
 *
 * Tampoco miran el reloj ni el inventario. `published_at` futuro es
 * publicación programada y **se evalúa en la consulta, sin job** (RB-5), así
 * que un producto `Published` con fecha futura da `true` aquí y aun así no se
 * lista. Y `stock = 0` **no** cambia el estado (RB-4): sigue `published`,
 * visible con su sello de agotado, y quien deshabilita el apartado por falta
 * de existencias es `CreateLayawayAction`, no este enum.
 *
 * Que los dos métodos devuelvan hoy lo mismo para los tres casos es un
 * accidente del dominio, no un duplicado: responden preguntas distintas
 * —mirar y comprometerse— y `REQ-CAT-007` SP-4 ya insinúa dónde se separan,
 * porque restaurar un archivado lo devuelve a `draft` y no a `published`.
 */
enum ProductStatus: string
{
    /** En preparación: invisible en todo catálogo y sin URL alcanzable. */
    case Draft = 'draft';

    /** Publicado: visible si además su catálogo es accesible (RB-6). */
    case Published = 'published';

    /** Retirado: invisible, conserva historial y no se puede apartar. */
    case Archived = 'archived';

    /**
     * Indica si el estado permite que el producto se renderice en un catálogo.
     *
     * `draft` y `archived` no se renderizan y su URL directa da `404`
     * (`REQ-CAT-007` SP-1). El archivado **sigue dentro del catálogo**: la
     * relación se conserva (RB-7) para que desarchivarlo lo devuelva sin
     * recomponer nada, y lo único que cambia es que no se pinta.
     */
    public function isVisibleInCatalog(): bool
    {
        return match ($this) {
            self::Published => true,
            self::Draft, self::Archived => false,
        };
    }

    /**
     * Indica si el estado permite abrir un apartado nuevo sobre el producto.
     *
     * Es condición necesaria y no suficiente: el formulario comprueba además
     * existencias, coherencia del plan de pago y el cupo del negocio
     * (`REQ-CAT-003` RB-7). Lo que este método fija es que sobre un `archived`
     * no se aparta **nunca**, aunque el formulario ya estuviera abierto
     * (`REQ-CAT-007` SP-2).
     */
    public function canBeReserved(): bool
    {
        return match ($this) {
            self::Published => true,
            self::Draft, self::Archived => false,
        };
    }
}
