import { BackLink } from '@/components/ui/back-link';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

it('vuelve a la página anterior con un área táctil de 44 px en el celular', () => {
    render(<BackLink href="/backoffice/cobranza">Cobranza</BackLink>);

    const link = screen.getByRole('link', { name: 'Cobranza' });

    expect(link).toHaveAttribute('href', '/backoffice/cobranza');
    expect(link).toHaveClass('touch:min-h-11', 'inline-flex', 'items-center');
    expect(link.querySelector('svg')).not.toBeNull();
});
