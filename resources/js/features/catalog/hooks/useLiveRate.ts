import { type RateView } from '@/types/exchange';
import { useQuery } from '@tanstack/react-query';

export const RATE_REFRESH_MS = 5 * 60 * 1000;

export const RATE_ENDPOINT = '/api/tasa';

async function fetchRate(source: RateView['source']): Promise<RateView> {
    const response = await fetch(`${RATE_ENDPOINT}?source=${encodeURIComponent(source)}`, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`La tasa no se pudo refrescar (${response.status}).`);
    }

    return (await response.json()) as RateView;
}

export interface LiveRate {
    rate: RateView;
    differsFromPrices: boolean;
}

export function useLiveRate(initial: RateView): LiveRate {
    const { data } = useQuery({
        queryKey: ['rate', initial.source, initial.publishedAt],
        queryFn: () => fetchRate(initial.source),
        initialData: initial,
        enabled: initial.source !== 'manual',
        staleTime: RATE_REFRESH_MS,
        refetchInterval: RATE_REFRESH_MS,
        refetchOnWindowFocus: false,
        retry: false,
    });

    return {
        rate: data,
        differsFromPrices: data.rate !== initial.rate || data.publishedAt !== initial.publishedAt,
    };
}
