import { SupportAccessPanel } from '@/features/platform/components/SupportAccessPanel';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
    useForm: () => ({ data: { reason: '' }, errors: {}, processing: false, setData: vi.fn(), post: vi.fn() }),
    Link: ({ children, ...rest }: { children: ReactNode } & Record<string, unknown>) => <a {...rest}>{children}</a>,
}));

it('un acceso vigente se marca como algo en curso, no como una advertencia', () => {
    render(
        <SupportAccessPanel
            slug="tecno-hogar"
            myGrant={null}
            grants={[
                {
                    id: 1,
                    operator: 'Operador',
                    reason: 'Revisar un apartado',
                    grantedAt: '2026-11-01T10:00:00-04:00',
                    expiresAt: '2026-11-02T10:00:00-04:00',
                    revokedAt: null,
                    active: true,
                },
            ]}
        />,
    );

    const badge = screen.getByText('Vigente');

    expect(badge.className).toMatch(/success/);
    expect(badge.className).not.toMatch(/warning/);
});
