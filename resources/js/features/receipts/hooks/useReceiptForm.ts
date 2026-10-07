import { BYTES_PER_MB } from '@/features/receipts/hooks/useFilePreview';
import { type Currency } from '@/types/money';
import { type ReceiptCreateProps, type ReceiptFormValues, type ReceiptSuggestion } from '@/types/receipt';
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

/**
 * El formulario del comprobante (`REQ-PAY-001`).
 *
 * Con el `useForm` de Inertia, como el del apartado: los errores del servidor
 * llegan por campo —incluido el duplicado de `REQ-PAY-003`, que aterriza en
 * `file` o en `reference`— y lo escrito se conserva tras un `422`. Va
 * multipart (`forceFormData`) porque lleva la captura.
 *
 * ## Lo que el formulario sugiere, y lo que no impone
 *
 *  - **La cuenta** (RB-3): si el negocio tiene una sola, va elegida. Elegir
 *    una cuenta elige también su método, que es lo que casi siempre se quiere.
 *  - **La moneda** (SP-10): elegir un método pone su moneda habitual, y se
 *    puede cambiar después; un pago en dólares por un método en bolívares es
 *    legítimo. La sugerencia calla en cuanto la persona **elige la moneda o
 *    escribe un monto**: cambiar la moneda bajo una cifra ya escrita
 *    convertiría «1275 bolívares» en «1275 dólares» sin que nadie lo note.
 *  - **El importe** (RB-9): se precarga con la sugerencia del servidor en la
 *    moneda elegida. En cuanto la persona escribe el suyo, cambiar de moneda ya
 *    no lo toca: pisar lo escrito es peor que no sugerir.
 *
 * ## Banco y referencia se vacían al no pedirse
 *
 * RB-2: solo los métodos con `requiresReference` los llevan, y el servidor
 * **rechaza** banco o referencia en efectivo. Ocultarlos sin vaciarlos
 * mandaría lo que se escribió antes de cambiar de método, y el `422` llegaría
 * sobre un campo que ya no se ve.
 *
 * ## El aviso de tamaño no bloquea
 *
 * Si el archivo pasa de `maxMb`, se avisa junto al campo y se deja enviar. Quien
 * rechaza es el servidor (`REQ-PAY-002` RB-5), con el límite vigente y el
 * mensaje explícito de SP-1; un segundo rechazo aquí sería otra fuente de la
 * misma regla, y la que se queda vieja cuando el ajuste de plataforma cambia.
 *
 * Aquí no se hace aritmética de dinero: las cifras sugeridas llegan como
 * cadenas del servidor y se copian tal cual.
 */

type UseReceiptFormOptions = Pick<ReceiptCreateProps, 'paymentAccounts' | 'methods' | 'currencies' | 'suggestion' | 'paidAt' | 'file' | 'storeUrl'>;

/**
 * La cifra sugerida en esa moneda, o vacío si no la hay.
 *
 * En bolívares sin tasa (`amountVes === null`) es vacío y no la cifra en
 * dólares: dejar `30.00` bajo «Bs.» sería declarar treinta bolívares.
 */
export function suggestedAmount(suggestion: ReceiptSuggestion | null, currency: Currency): string {
    if (suggestion === null) {
        return '';
    }

    return currency === 'USD' ? suggestion.amountUsd : (suggestion.amountVes ?? '');
}

export function useReceiptForm({ paymentAccounts, methods, currencies, suggestion, paidAt, file, storeUrl }: UseReceiptFormOptions) {
    const findMethod = (value: string) => methods.find((method) => method.value === value);

    const initialAccount = paymentAccounts.length === 1 ? paymentAccounts[0] : undefined;
    const initialMethod = initialAccount ? findMethod(initialAccount.method) : undefined;
    const initialCurrency: Currency = initialMethod?.defaultCurrency ?? currencies[0]?.value ?? 'USD';

    const form = useForm<ReceiptFormValues>({
        paymentAccount: initialAccount?.id ?? null,
        method: initialMethod?.value ?? '',
        bank: '',
        reference: '',
        paidAt: paidAt.max,
        amount: suggestedAmount(suggestion, initialCurrency),
        currency: initialCurrency,
        note: '',
        file: null,
    });

    /** Si el importe lo escribió la persona; vaciarlo devuelve la sugerencia. */
    const amountTyped = useRef(false);
    /** Si la persona eligió la moneda a mano: desde entonces el método ya no la sugiere. */
    const currencyChosen = useRef(false);

    const withCurrency = (data: ReceiptFormValues, currency: Currency): ReceiptFormValues => ({
        ...data,
        currency,
        amount: amountTyped.current ? data.amount : suggestedAmount(suggestion, currency),
    });

    const withMethod = (data: ReceiptFormValues, value: string): ReceiptFormValues => {
        const method = findMethod(value);
        const next: ReceiptFormValues = method?.requiresReference ? { ...data } : { ...data, bank: '', reference: '' };
        next.method = method?.value ?? '';

        const suggestsCurrency = method !== undefined && !currencyChosen.current && !amountTyped.current;

        return suggestsCurrency ? withCurrency(next, method.defaultCurrency) : next;
    };

    /** RB-3 · el valor del `select`; vacío es «sin cuenta» y viaja como `null`. */
    const selectAccount = (value: string) => {
        const account = paymentAccounts.find((candidate) => String(candidate.id) === value);

        form.setData((data) => {
            const next = { ...data, paymentAccount: account?.id ?? null };
            // Si su método ya está elegido, no hay nada que cambiar.
            const changesMethod = account !== undefined && account.method !== data.method && findMethod(account.method) !== undefined;

            return changesMethod ? withMethod(next, account.method) : next;
        });
    };

    const selectMethod = (value: string) => form.setData((data) => withMethod(data, value));

    const selectCurrency = (value: string) => {
        const currency = currencies.find((candidate) => candidate.value === value);

        if (currency) {
            currencyChosen.current = true;
            form.setData((data) => withCurrency(data, currency.value));
        }
    };

    const setAmount = (value: string) => {
        amountTyped.current = value.trim() !== '';
        form.setData('amount', value);
    };

    const setFile = (value: File | null) => form.setData('file', value);

    const submit = () => {
        form.post(storeUrl, { forceFormData: true, preserveScroll: true });
    };

    const { data } = form;

    return {
        ...form,
        selectAccount,
        selectMethod,
        selectCurrency,
        setAmount,
        setFile,
        submit,
        selectedAccount: paymentAccounts.find((account) => account.id === data.paymentAccount) ?? null,
        requiresReference: findMethod(data.method)?.requiresReference ?? false,
        fileTooLarge: data.file !== null && data.file.size > file.maxMb * BYTES_PER_MB,
    };
}

export type ReceiptForm = ReturnType<typeof useReceiptForm>;
