import { phoneAreaCode } from '@/lib/identity-format';
import { type LayawayFormValues } from '@/types/layaway';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export const PLAN_DEBOUNCE_MS = 300;

interface UseLayawayFormOptions {
    initialQuantity: number;
    maxQuantity: number;
    recognisedAreaCodes: readonly string[];
    storeUrl: string;
    prefill?: { readonly fullName: string; readonly email: string } | null;
}

export function useLayawayForm({ initialQuantity, maxQuantity, recognisedAreaCodes, storeUrl, prefill = null }: UseLayawayFormOptions) {
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<LayawayFormValues>({
        quantity: initialQuantity,
        fullName: prefill?.fullName ?? '',
        documentNumber: '',
        phone: '',
        email: prefill?.email ?? '',
        termsAccepted: false,
        idempotencyKey,
    });

    const { data } = form;
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = window.setTimeout(() => {
            router.reload({ only: ['plan'], data: { quantity: data.quantity } });
        }, PLAN_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [data.quantity]);

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
