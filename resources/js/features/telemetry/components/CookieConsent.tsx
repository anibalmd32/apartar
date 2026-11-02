import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useEffect, useRef } from 'react';

import { reopenConsent, useConsent } from '../hooks/useConsent';

export type ConsentSurface = 'catalog' | 'site';

const PURPOSE: Record<ConsentSurface, string> = {
    catalog: 'Medimos visitas y búsquedas para el negocio; tu IP se guarda cifrada.',
    site: 'Medimos visitas para mejorar Apartar; tu IP se guarda cifrada.',
};

const BOTTOM_GAP = 12;

function useReservedHeight(active: boolean) {
    const ref = useRef<HTMLElement>(null);

    useEffect(() => {
        const element = ref.current;
        const root = document.documentElement;

        if (!active || !element) {
            return;
        }

        const reserve = (): void => root.style.setProperty('--consent-height', `${Math.ceil(element.offsetHeight + BOTTOM_GAP)}px`);

        reserve();

        const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(reserve) : null;
        observer?.observe(element);

        return () => {
            observer?.disconnect();
            root.style.removeProperty('--consent-height');
        };
    }, [active]);

    return ref;
}

export function CookieConsent({ surface = 'catalog' }: { surface?: ConsentSurface }) {
    const { asking, choose } = useConsent();
    const ref = useReservedHeight(asking);

    if (!asking) {
        return null;
    }

    return (
        <section
            ref={ref}
            aria-label="Aviso de cookies"
            className="bg-base-100 border-line shadow-pop rounded-box fixed inset-x-3 bottom-[calc(var(--overlay-bottom)+0.75rem)] z-40 mx-auto flex max-w-3xl flex-col gap-3 border p-3 sm:flex-row sm:items-center sm:gap-4 sm:pl-4"
        >
            <p className="text-ink-2 min-w-0 flex-1 text-sm leading-snug">
                {PURPOSE[surface]}{' '}
                <a
                    href="/privacidad"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="link link-hover text-base-content font-semibold whitespace-nowrap"
                >
                    Más información
                </a>
            </p>
            <div className="grid shrink-0 grid-cols-2 gap-2">
                <Button variant="secondary" className="max-lg:h-11" onClick={() => choose('essential')}>
                    Solo lo esencial
                </Button>
                <Button variant="secondary" className="max-lg:h-11" onClick={() => choose('all')}>
                    Aceptar todo
                </Button>
            </div>
        </section>
    );
}

export function CookiePreferencesLink({ className = 'link link-hover' }: { className?: string }) {
    return (
        <button type="button" className={cn('hit-area', className)} onClick={reopenConsent}>
            Preferencias de cookies
        </button>
    );
}
