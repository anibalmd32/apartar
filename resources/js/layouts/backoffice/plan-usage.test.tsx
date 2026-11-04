import { PlanUsage } from '@/layouts/backoffice/plan-usage';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const limits = vi.hoisted(() => ({ near: false }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

vi.mock('@/features/plan/hooks/usePlanLimits', () => ({
    usePlanLimits: () => ({
        state: { planName: 'Gratis' },
        canOpenSubscription: true,
        usage: () => ({ used: 27, limit: 30, percentage: 90 }),
        isNearLimit: () => limits.near,
    }),
}));

it('el uso del plan no ocupa el menú mientras el negocio está lejos del límite', () => {
    limits.near = false;
    const { container } = render(<PlanUsage />);

    expect(container).toBeEmptyDOMElement();
});

it('cerca del límite avisa en el menú y lleva a la suscripción', () => {
    limits.near = true;
    render(<PlanUsage />);

    expect(screen.getByText('Te acercas al límite')).toBeInTheDocument();
    expect(screen.getByText('27 de 30 productos')).toBeInTheDocument();
    expect(screen.getByRole('link')).toHaveAttribute('href', '/backoffice/suscripcion');
});
