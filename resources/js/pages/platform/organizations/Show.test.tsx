import OrganizationShow from '@/pages/platform/organizations/Show';
import { type OrganizationRow } from '@/types/platform';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/layouts/platform-layout', () => ({
    default: ({ children, actions }: { children: ReactNode; actions?: ReactNode }) => (
        <main>
            {actions}
            {children}
        </main>
    ),
}));

vi.mock('@/features/platform/components/SupportAccessPanel', () => ({ SupportAccessPanel: () => null }));
vi.mock('@/features/platform/components/SubscriptionActions', () => ({ SubscriptionActions: () => null }));
vi.mock('@/features/platform/components/InvoiceList', () => ({ InvoiceList: () => null }));

const organization = {
    id: 1,
    slug: 'tecno-store',
    name: 'Tecno Store VE',
    status: 'active',
    planCode: 'business',
    subscriptionStatus: 'active',
    createdAt: '2026-03-02T12:00:00Z',
    counts: { users: 4, products: 1204, publishedCatalogs: 31, customers: 900, activeLayaways: 880, subscribers: 300 },
    volume: null,
    lastActivityAt: null,
    risks: { atLimit: false, inactive: false, overdueInvoices: false, supportAccess: false },
} as unknown as OrganizationRow;

it('enseña el techo de cada consumo, el perfil y confirma antes de suspender', () => {
    render(
        <OrganizationShow
            organization={organization}
            invoices={[]}
            grants={[]}
            myGrant={null}
            plans={[]}
            limits={{ products: 2000, users: 15, catalogs: 50, active_layaways: 2000 }}
            profile={{
                ownerName: 'Carlos Pérez',
                ownerEmail: 'carlos@tecnostore.ve',
                phone: null,
                document: null,
                storefrontUrl: null,
                timezone: 'America/Caracas',
            }}
            suspendable
        />,
    );

    expect(screen.getAllByText('/ 2000', { exact: false })).toHaveLength(2);
    expect(screen.getByText('/ 15', { exact: false })).toBeInTheDocument();
    expect(screen.getByText('Carlos Pérez')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Suspender' }));
    expect(post).not.toHaveBeenCalled();

    const buttons = screen.getAllByRole('button', { name: 'Suspender' });
    fireEvent.click(buttons[buttons.length - 1]!);
    expect(post).toHaveBeenCalledWith('/plataforma/organizaciones/tecno-store/pausar', {}, expect.any(Object));
});
