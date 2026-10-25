import { FILTER_DEBOUNCE_MS } from '@/features/data-table/hooks/useDebouncedFilter';
import { getJson } from '@/lib/json';
import { useEffect, useState } from 'react';

export type SlugAvailability =
    | { readonly state: 'idle' }
    | { readonly state: 'free'; readonly slug: string }
    | { readonly state: 'taken'; readonly slug: string; readonly suggestion: string | null };

export function useSlugAvailability(slug: string): SlugAvailability {
    const [result, setResult] = useState<SlugAvailability>({ state: 'idle' });
    const term = slug.trim();

    useEffect(() => {
        if (term === '') {
            return;
        }

        const timer = window.setTimeout(() => {
            void getJson<{ slug: string; available: boolean; suggestion: string | null }>(
                `${route('register.slug')}?${new URLSearchParams({ slug: term }).toString()}`,
            )
                .then((body) =>
                    setResult(
                        body.slug === ''
                            ? { state: 'idle' }
                            : body.available
                              ? { state: 'free', slug: body.slug }
                              : { state: 'taken', slug: body.slug, suggestion: body.suggestion },
                    ),
                )
                .catch(() => setResult({ state: 'idle' }));
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [term]);

    return term === '' ? { state: 'idle' } : result;
}
