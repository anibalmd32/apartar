import { type ApprovalPreviewView } from '@/types/receipt';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

export const PREVIEW_DEBOUNCE_MS = 400;

export interface ApprovalValues {
    readonly amount: string;
    readonly currency: string;
    readonly rate: string;
}

export interface ApprovalPreviewState {
    readonly preview: ApprovalPreviewView | null;
    readonly error: string | null;
    readonly loading: boolean;
}

type Answer = { readonly preview: ApprovalPreviewView; readonly error: null } | { readonly preview: null; readonly error: string };

async function ask(url: string, values: ApprovalValues): Promise<Answer> {
    const query = new URLSearchParams({ amount: values.amount, currency: values.currency });

    if (values.rate !== '') {
        query.set('rate', values.rate);
    }

    const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}${query.toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (response.status === 422) {
        const body = (await response.json()) as { message: string };

        return { preview: null, error: body.message };
    }

    if (!response.ok) {
        throw new Error(`La vista previa no respondió (${response.status}).`);
    }

    const body = (await response.json()) as { preview: ApprovalPreviewView };

    return { preview: body.preview, error: null };
}

function same(a: ApprovalValues, b: ApprovalValues): boolean {
    return a.amount === b.amount && a.currency === b.currency && a.rate === b.rate;
}

export function useApprovalPreview(
    url: string,
    values: ApprovalValues,
    initial: ApprovalValues & { preview: ApprovalPreviewView | null; error: string | null },
    revision = '',
): ApprovalPreviewState {
    const [settled, setSettled] = useState(values);
    const { amount, currency, rate } = values;

    useEffect(() => {
        const timer = window.setTimeout(() => setSettled({ amount, currency, rate }), PREVIEW_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [amount, currency, rate]);

    const asIs = same(settled, initial) && revision === '';

    const { data, isFetching, isError } = useQuery({
        queryKey: ['receipts', 'approval-preview', url, settled.amount, settled.currency, settled.rate, revision],
        queryFn: () => ask(url, settled),
        enabled: !asIs && settled.amount !== '',
        placeholderData: keepPreviousData,
        staleTime: Infinity,
        retry: false,
    });

    if (asIs) {
        return { preview: initial.preview, error: initial.error, loading: !same(values, settled) };
    }

    if (isError) {
        return { preview: null, error: 'No se pudo calcular la vista previa. Revisa la conexión e inténtalo de nuevo.', loading: false };
    }

    return {
        preview: data?.preview ?? null,
        error: data?.error ?? null,
        loading: isFetching || !same(values, settled),
    };
}
