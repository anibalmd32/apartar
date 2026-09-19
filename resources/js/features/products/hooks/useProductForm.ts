import { type SpecificationPayloadRow } from '@/features/products/hooks/useSpecificationRows';
import { type ProductDefaults, type ProductFormValues } from '@/types/product';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * El formulario de producto y su previsualización en vivo
 * (`REQ-BOF-020` RB-4, RB-7).
 *
 * ## Por qué la previsualización pide la cuenta al servidor
 *
 * RB-7 pide la frase «inicial $50 + 5 cuotas de $30 cada 15 días» **usando el
 * mismo cálculo del dominio que generará el cronograma real**. La división
 * cabe en una línea de TypeScript y por eso es tan fácil escribirla aquí — y
 * sería una segunda cuenta: el servidor reparte con `Money::allocateEvenly()`
 * y deja el residuo en la última cuota, y un `/` en JavaScript reparte con
 * coma flotante y lo pierde. El negocio vería una cuota en el formulario y su
 * cliente otra en el catálogo, por un céntimo.
 *
 * Así que los cuatro términos se mandan al servidor y vuelve el plan ya
 * repartido y ya formateado. **No hace falta un endpoint nuevo**: la
 * previsualización es una `prop` de esta misma pantalla, y una recarga parcial
 * de Inertia (`only: ['plan']`) la refresca sin tocar el resto del formulario
 * ni perder lo que se está escribiendo.
 *
 * Lo que impide que alguien reimplemente la división mañana no es este
 * comentario: es que **el plan llega como texto ya formateado**
 * (`MoneyView.usd`), no como un número con el que se pueda dividir.
 *
 * ## Y por qué va con retardo
 *
 * 300 ms desde la última tecla, el mismo que la búsqueda del catálogo
 * (`REQ-CAT-002` RB-6): una petición por pulsación sería una petición por
 * pulsación. `preserveState` y `preserveScroll` mantienen el formulario
 * exactamente donde está; lo único que cambia en la pantalla es la frase del
 * plan.
 */

/** Lo que el formulario manda, con los nombres que espera el servidor. */
export interface ProductFormData {
    [key: string]: string | number | number[] | string[];
    name: string;
    sku: string;
    slug: string;
    description: string;
    price: string;
    compare_at_price: string;
    down_payment: string;
    installments_count: number;
    frequency_days: number;
    stock: number;
    category_id: string;
    category_name: string;
    tag_ids: number[];
    tag_names: string[];
}

/** Milisegundos desde la última tecla antes de pedir el plan. */
const PREVIEW_DEBOUNCE_MS = 300;

/**
 * Los valores iniciales: los del producto que se edita, o los defaults de la
 * organización (RB-4).
 */
function initialData(defaults: ProductDefaults, product?: ProductFormValues | null): ProductFormData {
    return {
        name: product?.name ?? '',
        sku: product?.sku ?? '',
        slug: product?.slug ?? '',
        description: product?.description ?? '',
        price: product?.price ?? '',
        compare_at_price: product?.compareAtPrice ?? '',
        down_payment: product?.downPayment ?? '0.00',
        // RB-4 · los defaults del negocio, no constantes escritas aquí: cada
        // organización fija los suyos una vez y no los reescribe en cada
        // producto.
        installments_count: product?.installmentsCount ?? defaults.installmentsCount,
        frequency_days: product?.frequencyDays ?? defaults.frequencyDays,
        stock: product?.stock ?? 0,
        category_id: product?.categoryId === null || product?.categoryId === undefined ? '' : String(product.categoryId),
        category_name: '',
        tag_ids: product ? [...product.tagIds] : [],
        tag_names: [],
    };
}

export function useProductForm(defaults: ProductDefaults, product?: ProductFormValues | null) {
    const form = useForm<ProductFormData>(initialData(defaults, product));

    const { data } = form;

    // Los cuatro términos del plan, y solo ellos: cambiar el nombre o las
    // existencias no cambia ninguna cuota y no tiene por qué pedir nada.
    const terms = [data.price, data.down_payment, data.installments_count, data.frequency_days] as const;

    const firstRender = useRef(true);

    useEffect(() => {
        // En el primer render el plan ya vino con la página: volver a pedirlo
        // sería una petición para recibir lo que ya está en pantalla.
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = window.setTimeout(() => {
            // `reload` ya conserva el estado y el scroll por definición —es
            // una recarga parcial de la misma página—, así que no hace falta
            // pedírselo: lo único que se reemplaza es la `prop` nombrada.
            router.reload({
                only: ['plan'],
                data: {
                    price: data.price,
                    down_payment: data.down_payment,
                    installments_count: data.installments_count,
                    frequency_days: data.frequency_days,
                },
            });
        }, PREVIEW_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, terms);

    /**
     * Envía el producto **con su ficha técnica**, en la misma operación
     * (`REQ-BOF-021` RB-8).
     *
     * Las filas llegan por parámetro y se adjuntan con `transform()` en vez de
     * vivir en `data`: su orden y sus claves locales los gobierna
     * `useSpecificationRows`, y duplicarlas aquí sería una segunda lista que
     * se queda vieja en cuanto alguien reordena. Se envían **siempre**, aunque
     * sean cero: la lista vacía es lo que borra la ficha entera (SP-3).
     */
    const submit = (specifications: SpecificationPayloadRow[]) => {
        form.transform((current) => ({ ...current, specifications }));

        if (product) {
            form.put(`/backoffice/productos/${product.id}`, { preserveScroll: true });

            return;
        }

        form.post('/backoffice/productos', { preserveScroll: true });
    };

    return { ...form, submit };
}

/**
 * Lo que necesita cada bloque de campos del formulario.
 *
 * Se deriva del propio hook en lugar de escribirse a mano: `setData` de
 * Inertia tiene tres sobrecargas y redeclararlas aquí sería una copia que se
 * queda vieja en la siguiente versión del paquete.
 */
export type ProductFieldsProps = Pick<ReturnType<typeof useProductForm>, 'data' | 'setData' | 'errors'>;
