import { type SpecificationGroupView } from '@/types/product';

/**
 * La ficha técnica tal como la lee el cliente (`REQ-CAT-004`).
 *
 * **La pieza ahora, la página en su tarea.** `ProductDetailResource` y la
 * ficha pública son de `T-035`; esto es el componente que aquella montará, y
 * llega con sus pruebas, igual que `MoneyLabel` llegó antes que la pantalla
 * que lo usa.
 *
 * ## El escapado es el comportamiento por defecto, y eso es el punto
 *
 * RB-4 y SP-4: `label` y `value` se renderizan como **texto plano escapado**.
 * Aquí no se sanea nada —eso se haría al guardar, y convertiría la medida
 * «< 2 kg» en `&lt; 2 kg` en la base— y **no hay ningún
 * `dangerouslySetInnerHTML`**: React escapa el texto solo. La regla que este
 * archivo tiene que sostener es negativa, así que su prueba también lo es:
 * comprueba que un `<script>` escrito por el negocio se lee como texto y no
 * llega al DOM como elemento.
 *
 * ## Lo que ya viene resuelto del servidor
 *
 * El orden y la agrupación los hace `App\Domain\Catalog\SpecificationGroups`:
 * la sección sin nombre primero (RB-2), dentro de cada una por `position` y a
 * igualdad por `id` (RB-3), y «Motor» y «motor» como una sola con la primera
 * variante (SP-2). Aquí no se ordena ni se agrupa; rehacerlo sería una segunda
 * opinión sobre lo mismo.
 *
 * Lo único que sí decide esta pantalla es **el rótulo «General»**, y por eso el
 * servidor manda `group: null`: traducirlo allí haría indistinguible una ficha
 * sin secciones de una con una sección llamada así.
 *
 * ## Y el ancho no se lo lleva la página
 *
 * RB-5: pares apilados en móvil y dos columnas desde `md`, dentro de un
 * contenedor con `overflow-x` propio. Una medida larga —«1200 × 800 × 450 mm»—
 * tiene que poder desbordar **su** caja sin que el `body` scrollee en
 * horizontal, que es el defecto que se nota en toda la pantalla y no solo
 * aquí.
 */

interface SpecificationTableProps {
    groups: readonly SpecificationGroupView[];
}

/** Rótulo de las filas sin sección (RB-2). */
const GENERAL = 'General';

export function SpecificationTable({ groups }: SpecificationTableProps) {
    // SP-5 de `REQ-BOF-021` · sin filas no se pinta un encabezado vacío: la
    // sección se omite entera y es la página quien decide qué poner en su
    // lugar, si algo.
    if (groups.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4">
            {groups.map((group) => (
                <section key={group.group ?? GENERAL} className="flex flex-col gap-2">
                    <h3 className="text-sm font-semibold">{group.group ?? GENERAL}</h3>

                    <div className="overflow-x-auto">
                        <dl className="grid grid-cols-1 gap-x-4 gap-y-1 md:grid-cols-[minmax(8rem,auto)_1fr]">
                            {group.items.map((item, index) => (
                                <div key={`${item.label}-${index}`} className="contents">
                                    <dt className="text-sm opacity-70">{item.label}</dt>
                                    {/* `whitespace-pre-line` · `REQ-CAT-004` SP-1
                                        permite un valor largo con saltos de
                                        línea y prohíbe recortarlo: si el negocio
                                        los escribió, se leen. */}
                                    <dd className="text-sm whitespace-pre-line">{item.value}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </section>
            ))}
        </div>
    );
}
