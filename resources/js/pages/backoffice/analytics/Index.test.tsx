import AnalyticsIndex from '@/pages/backoffice/analytics/Index';
import { type AnalyticsPageProps } from '@/types/analytics';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/analitica', props: { errors: {} } }),
    Link: ({ href, children, className, ...rest }: { href: string; children: ReactNode; className?: string; 'aria-current'?: 'page' }) => (
        <a href={href} className={className} aria-current={rest['aria-current']}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children, actions }: { children: ReactNode; actions?: ReactNode }) => (
        <main>
            {actions}
            {children}
        </main>
    ),
}));

vi.mock('@/features/plan/components/UpgradePrompt', () => ({ UpgradePrompt: () => <p>Sube de plan</p> }));

vi.stubGlobal('route', (_name: string, params: { periodo: number }) => `/backoffice/analitica?periodo=${params.periodo}`);

function props(full: boolean): AnalyticsPageProps {
    return {
        period: 30,
        periods: [7, 30, 90],
        full,
        analytics: {
            totals: { visits: 340, visitors: 210, layaways: 12 },
            funnel: full
                ? [
                      { event: 'catalog_viewed', count: 340 },
                      { event: 'product_viewed', count: 180 },
                      { event: 'layaway_form_started', count: 42 },
                      { event: 'layaway_created', count: 12 },
                  ]
                : null,
            catalogs: full ? [{ name: 'Ofertas', slug: 'ofertas', visits: 340, layaways: 12 }] : null,
            topProducts: full ? [{ name: 'Taladro', views: 90 }] : null,
            searchesWithoutResults: full ? [] : null,
            monthly: full ? [{ month: '2026-10', visits: 340, subscriptions: 5 }] : null,
        },
    };
}

it('con la analítica completa enseña el embudo 340 → 180 → 42 → 12, con su conversión', () => {
    render(<AnalyticsIndex {...props(true)} />);

    const steps = within(screen.getByRole('region', { name: 'Embudo' })).getAllByRole('listitem');
    expect(steps.map((item) => item.lastElementChild?.textContent)).toEqual([
        'Vieron un catálogo340 100 %',
        'Abrieron un producto180 53 %',
        'Empezaron a apartar42 12 %',
        'Apartaron12 3,5 %',
    ]);
    expect(steps.map((item) => item.querySelector('[data-step-conversion]')?.textContent ?? null)).toEqual([
        null,
        '↓ 53 % del paso anterior',
        '↓ 23 % del paso anterior',
        '↓ 29 % del paso anterior',
    ]);
    expect(screen.getByText('Todas las búsquedas encontraron algo.')).toBeInTheDocument();
    expect(screen.queryByText('Sube de plan')).toBeNull();
});

it('en Free, los tres conteos y la propuesta de subir de plan', () => {
    render(<AnalyticsIndex {...props(false)} />);

    const numbers = within(screen.getByRole('region', { name: 'Cifras' }));
    expect(numbers.getByText('340')).toBeInTheDocument();
    expect(numbers.getByText('Sube de plan')).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Embudo' })).toBeNull();
});

it('marca el período elegido', () => {
    render(<AnalyticsIndex {...props(true)} />);

    expect(screen.getByRole('link', { name: '30 días' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: '7 días' })).toHaveAttribute('href', '/backoffice/analitica?periodo=7');
    expect(screen.getByRole('link', { name: '7 días' })).not.toHaveAttribute('aria-current');
});

it('compara con el período anterior y ofrece elegir catálogo', () => {
    render(
        <AnalyticsIndex
            {...props(true)}
            previous={{ visits: 300, visitors: 210, layaways: 0 }}
            catalogOptions={[
                { value: 'ofertas', label: 'Ofertas' },
                { value: 'navidad', label: 'Navidad' },
            ]}
        />,
    );

    expect(screen.getByText('+13% vs. período anterior')).toBeInTheDocument();
    expect(screen.getByText('+0% vs. período anterior')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Catálogo' })).toHaveValue('');
});
