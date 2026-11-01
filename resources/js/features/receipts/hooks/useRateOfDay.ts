import { useQuery } from '@tanstack/react-query';

export interface RateOfDay {
    readonly rate: string;
    readonly sourceLabel: string;
    readonly date: string;
}

export interface RateOfDayState {
    readonly rate: RateOfDay | null;
    readonly loading: boolean;
    readonly known: boolean;
}

async function ask(url: string, day: string): Promise<RateOfDay | null> {
    const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}${new URLSearchParams({ paid_at: day }).toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`La tasa del día no respondió (${response.status}).`);
    }

    return ((await response.json()) as { rate: RateOfDay | null }).rate;
}

export function useRateOfDay(url: string, day: string, enabled: boolean): RateOfDayState {
    const { data, isFetching, isSuccess } = useQuery({
        queryKey: ['payments', 'rate-of-day', url, day],
        queryFn: () => ask(url, day),
        enabled: enabled && day !== '',
        staleTime: 5 * 60 * 1000,
        retry: false,
    });

    return { rate: data ?? null, loading: isFetching, known: isSuccess };
}
