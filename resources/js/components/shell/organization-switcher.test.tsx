import { OrganizationSwitcher } from '@/components/shell/organization-switcher';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({ router: { post: (...args: unknown[]) => post(...args) } }));

const ORGANIZATIONS = [
    { name: 'Boutique Mariela', slug: 'boutique-mariela', role: 'owner', current: true, switchUrl: '/backoffice/negocios/boutique-mariela/entrar' },
    { name: 'Zapatería Luz', slug: 'zapateria-luz', role: 'member', current: false, switchUrl: '/backoffice/negocios/zapateria-luz/entrar' },
] as const;

it('con un solo negocio no ofrece cambiar', () => {
    render(<OrganizationSwitcher name="Boutique Mariela" detail="Plan Pro · Dueño" organizations={[]} />);

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText('Boutique Mariela')).toBeInTheDocument();
});

it('con varios, cambia al elegido', () => {
    render(<OrganizationSwitcher name="Boutique Mariela" detail="Plan Pro · Dueño" organizations={ORGANIZATIONS} />);

    fireEvent.click(screen.getByRole('button', { name: /Cambiar de negocio/ }));
    fireEvent.click(screen.getByText('Zapatería Luz'));

    expect(post).toHaveBeenCalledWith('/backoffice/negocios/zapateria-luz/entrar');
});
