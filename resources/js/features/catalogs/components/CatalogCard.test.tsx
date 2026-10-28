import { CatalogCard } from '@/features/catalogs/components/CatalogCard';
import { type CatalogListItem } from '@/types/catalog';
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

function catalog(overrides: Partial<CatalogListItem> = {}): CatalogListItem {
    return {
        id: 1,
        name: 'Otoño 2026',
        slug: 'otono-2026',
        visibility: 'listed',
        isListed: true,
        publishedAt: '2026-09-01T00:00:00Z',
        expiresAt: null,
        shareUrl: 'https://apartar.app/c/otono-2026',
        slugIsLocked: true,
        productsCount: 24,
        updatedAt: null,
        updatedBy: null,
        ...overrides,
    };
}

it('enseña las fotos de sus productos y cuántos lo siguen', () => {
    const { container } = render(<CatalogCard catalog={catalog({ subscribersCount: 312, coverUrl: null, thumbs: ['/a.jpg', '/b.jpg'] })} />);

    expect(screen.getByText('312 suscriptores')).toBeInTheDocument();
    expect(container.querySelectorAll('img')).toHaveLength(2);
});
