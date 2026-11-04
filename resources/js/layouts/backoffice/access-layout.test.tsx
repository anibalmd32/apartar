import AccessLayout from '@/layouts/backoffice/access-layout';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));

afterEach(() => {
    delete window.apartarSurfaces;
});

beforeEach(() => {
    window.apartarSurfaces = { backoffice: '', platform: '', site: 'https://apartar.app' };
});

it('en el acceso del negocio el logo lleva a la landing del dominio principal, no a la raíz del subdominio del negocio', () => {
    render(<AccessLayout title="Entra a tu negocio">contenido</AccessLayout>);

    const logo = screen.getByRole('link', { name: 'Apartar, ir al inicio' });

    expect(logo).toHaveAttribute('href', 'https://apartar.app/');
    expect(logo.tagName).toBe('A');
});

it('el acceso del negocio deja cambiar entre modo claro y oscuro', () => {
    render(<AccessLayout title="Entra a tu negocio">contenido</AccessLayout>);

    expect(screen.getByRole('button', { name: /Cambiar a modo (oscuro|claro)/ })).toBeInTheDocument();
});
