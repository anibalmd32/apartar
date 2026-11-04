import AccessLayout from '@/layouts/backoffice/access-layout';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => (name === 'home' ? 'https://apartar.app/' : `/${name}`));
});

it('en el acceso del negocio el logo lleva a la landing, aunque esté en otro dominio', () => {
    render(<AccessLayout title="Entra a tu negocio">contenido</AccessLayout>);

    const logo = screen.getByRole('link', { name: 'Apartar, ir al inicio' });

    expect(logo).toHaveAttribute('href', 'https://apartar.app/');
    expect(logo.tagName).toBe('A');
});

it('el acceso del negocio deja cambiar entre modo claro y oscuro', () => {
    render(<AccessLayout title="Entra a tu negocio">contenido</AccessLayout>);

    expect(screen.getByRole('button', { name: /Cambiar a modo (oscuro|claro)/ })).toBeInTheDocument();
});
