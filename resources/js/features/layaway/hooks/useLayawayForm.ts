import { phoneAreaCode } from '@/lib/identity-format';
import { type LayawayFormValues } from '@/types/layaway';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * El formulario de apartado (`REQ-LAY-001`, `REQ-IDN-005`).
 *
 * Con el `useForm` de Inertia, como el de producto, y no con `react-form`: la
 * decisión y su motivo están escritos bajo `REQ-BOF-021` en [31]. En corto:
 * lo que este formulario necesita —errores por campo que llegan del servidor,
 * el estado de envío, conservar lo escrito tras un `422`— es exactamente lo que
 * Inertia ya da, y un segundo motor partiría en dos la fuente de los errores.
 *
 * ## La clave se genera una vez, al montar
 *
 * RB-12: el servidor reconoce un doble clic porque los dos envíos llevan **la
 * misma** `idempotencyKey`, y devuelve el mismo apartado. Por eso se crea en el
 * estado inicial y no en `submit()`: regenerarla en cada envío dejaría a la
 * idempotencia sin nada que comparar, y un doble clic sobre la última unidad
 * crearía un apartado y le daría al segundo un «no quedan unidades».
 *
 * ## El resumen lo calcula el servidor
 *
 * Al cambiar la cantidad, una recarga parcial de `plan` —la de la
 * previsualización del formulario de producto—, con retardo. El plan de dos
 * unidades **no** es el de una multiplicado (`allocateEvenly()` no es lineal),
 * y aquí no llega nada con lo que dividir.
 *
 * ## El aviso del teléfono no bloquea
 *
 * Si el código del teléfono escrito no está en el censo que manda el servidor,
 * se avisa y se deja seguir (`T-040`): rechazar un número real pierde la venta
 * sin dejar rastro. El teléfono **no** viaja al servidor para preguntarlo: una
 * consulta por `GET` lo dejaría escrito en una URL.
 */

/** El mismo retardo que la previsualización del plan del producto. */
export const PLAN_DEBOUNCE_MS = 300;

interface UseLayawayFormOptions {
    initialQuantity: number;
    maxQuantity: number;
    recognisedAreaCodes: readonly string[];
    storeUrl: string;
}

export function useLayawayForm({ initialQuantity, maxQuantity, recognisedAreaCodes, storeUrl }: UseLayawayFormOptions) {
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<LayawayFormValues>({
        quantity: initialQuantity,
        fullName: '',
        documentNumber: '',
        phone: '',
        email: '',
        termsAccepted: false,
        idempotencyKey,
    });

    const { data } = form;
    const firstRender = useRef(true);

    useEffect(() => {
        // El plan de la cantidad inicial ya vino con la página.
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = window.setTimeout(() => {
            router.reload({ only: ['plan'], data: { quantity: data.quantity } });
        }, PLAN_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [data.quantity]);

    /** RB-5 · entre 1 y `min(stock, 10)`, el mismo tope que aplica el servidor. */
    const setQuantity = (value: number) => {
        form.setData('quantity', Number.isFinite(value) ? Math.min(Math.max(Math.trunc(value), 1), Math.max(maxQuantity, 1)) : 1);
    };

    const areaCode = phoneAreaCode(data.phone);
    const unrecognisedPrefix = areaCode !== null && ['2', '4'].includes(areaCode.charAt(0)) && !recognisedAreaCodes.includes(areaCode);

    const submit = () => {
        form.post(storeUrl, { preserveScroll: true });
    };

    return { ...form, setQuantity, unrecognisedPrefix, submit };
}

export type LayawayForm = ReturnType<typeof useLayawayForm>;
