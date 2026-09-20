import { type CatalogProductOption } from '@/types/catalog';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * La composición de un catálogo mientras se edita (`REQ-CAT-023`).
 *
 * Qué productos entran, en qué orden se ven, y cuándo eso llega al servidor.
 *
 * ## El guardado es explícito, y es una regla (RB-8)
 *
 * Arrastrar **no guarda**: hay un botón. Componer un escaparate son veinte
 * gestos seguidos —añadir, mover, mover otra vez, quitar— y un autoguardado
 * por arrastre mandaría veinte peticiones para una sola decisión, dejando el
 * catálogo publicado pasando por diecinueve órdenes que el negocio no eligió.
 * De ahí que este hook lleve su propio estado y un `isDirty`: lo que está en
 * pantalla y lo que está guardado son dos cosas distintas hasta que se pulsa.
 *
 * ## El orden de este array **es** el orden
 *
 * No hay `position` en el cliente, igual que en la ficha técnica de `T-028`:
 * se manda la lista tal como se ve y el servidor la renumera contigua desde 1
 * (RB-2). Llevar aquí un número que el servidor va a recalcular sería una
 * segunda verdad sobre el orden, y la que se queda vieja es siempre la del
 * formulario.
 *
 * ## Y los rechazos los decide el servidor
 *
 * Vaciar un catálogo publicado (SP-7) y añadir un archivado (SP-1) son `422`.
 * Aquí se **avisa** de lo primero antes de pulsar, porque es lo único que la
 * pantalla sabe con certeza, pero el botón sigue activo: quien manda es la
 * acción, y deshabilitar por una regla que la pantalla no puede sostener del
 * todo sería prometer lo que no se sabe.
 */

interface SortableProductsOptions {
    catalogId: number;
    /** Los que ya están en el catálogo, en su orden curado. */
    initial: readonly CatalogProductOption[];
}

export function useSortableProducts({ catalogId, initial }: SortableProductsOptions) {
    const [chosen, setChosen] = useState<CatalogProductOption[]>(() => [...initial]);
    const [saving, setSaving] = useState(false);

    const savedIds = useMemo(() => initial.map((product) => product.id), [initial]);

    const ids = chosen.map((product) => product.id);

    const isDirty = ids.length !== savedIds.length || ids.some((id, index) => id !== savedIds[index]);

    const add = (product: CatalogProductOption) => {
        // RB-4 · un producto aparece una sola vez por catálogo. Añadir dos
        // veces es un doble clic, no una intención — y el servidor lo
        // deduplicaría igual (SP-2), pero entonces la pantalla habría enseñado
        // una lista que no era la que se iba a guardar.
        setChosen((current) => (current.some((row) => row.id === product.id) ? current : [...current, product]));
    };

    const remove = (id: number) => {
        setChosen((current) => current.filter((product) => product.id !== id));
    };

    /**
     * Mueve un producto de una posición a otra.
     *
     * Se saca y se vuelve a meter, que es lo que hace un arrastre: el
     * intercambio de dos elementos —lo que hacían los botones de subir y
     * bajar— daría otro resultado al mover tres sitios de golpe.
     */
    const reorder = (from: number, to: number) => {
        setChosen((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);

            if (moved === undefined) {
                return current;
            }

            next.splice(to, 0, moved);

            return next;
        });
    };

    const save = () => {
        setSaving(true);

        router.patch(`/backoffice/catalogos/${catalogId}/productos`, { products: ids }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    return { chosen, ids, isDirty, saving, add, remove, reorder, save };
}
