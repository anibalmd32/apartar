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

const FRAME: Record<ConsentSurface, string> = {
    catalog: 'bg-base-100 border-line shadow-pop rounded-box border',
    site: 'pizarra bg-pz-board rounded-md shadow-[0_14px_32px_-12px_rgb(0_0_0/0.35),inset_0_0_0_2px_var(--pz-ink)]',
};

const ACTION: Record<ConsentSurface, string> = {
    catalog: '',
    site: 'pz-btn pz-btn-line min-h-11 px-3 text-[0.9375rem]',
};

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
            className={cn(
                FRAME[surface],
                'fixed inset-x-3 bottom-[calc(var(--overlay-bottom)+0.75rem)] z-40 mx-auto flex max-w-3xl flex-col gap-3 p-3 sm:flex-row sm:items-center sm:gap-4 sm:pl-4',
            )}
        >
            <p className={cn('min-w-0 flex-1 text-sm leading-snug', surface === 'site' ? 'text-pz-ink-2' : 'text-ink-2')}>
                {PURPOSE[surface]}{' '}
                <a
                    href="/privacidad"
                    target="_blank"
                    rel="noopener noreferrer"
                    className={cn(
                        'font-semibold whitespace-nowrap',
                        surface === 'site' ? 'text-pz-ink underline' : 'link link-hover text-base-content',
                    )}
                >
                    Más información
                </a>
            </p>
            <div className="grid shrink-0 grid-cols-2 gap-2">
                {surface === 'site' ? (
                    <button type="button" className={ACTION.site} onClick={() => choose('essential')}>
                        Solo lo esencial
                    </button>
                ) : (
                    <Button variant="secondary" className="max-lg:h-11" onClick={() => choose('essential')}>
                        Solo lo esencial
                    </Button>
                )}
                {surface === 'site' ? (
                    <button type="button" className={ACTION.site} onClick={() => choose('all')}>
                        Aceptar todo
                    </button>
                ) : (
                    <Button variant="secondary" className="max-lg:h-11" onClick={() => choose('all')}>
                        Aceptar todo
                    </Button>
                )}
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
