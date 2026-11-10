import Login from '@/pages/backoffice/auth/Login';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

const useForm = vi.fn();

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: {} }),
    useForm: (initial: Record<string, unknown>) => useForm(initial),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/layouts/backoffice/access-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
    useForm.mockReset();
    useForm.mockImplementation((initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }));
});

it('con un correo recordado lo trae escrito, deja la casilla marcada y pone el foco en la contraseña', () => {
    render(<Login rememberedEmail="ana@ejemplo.test" />);

    expect(useForm).toHaveBeenCalledWith({ email: 'ana@ejemplo.test', password: '', remember_email: true });
    expect(screen.getByLabelText('Correo')).toHaveValue('ana@ejemplo.test');
    expect(screen.getByRole('checkbox', { name: 'Recordar mi correo' })).toBeChecked();
    expect(screen.getByLabelText('Contraseña')).toHaveFocus();
});

it('sin correo recordado empieza vacío con el foco en el correo', () => {
    render(<Login />);

    expect(useForm).toHaveBeenCalledWith({ email: '', password: '', remember_email: false });
    expect(screen.getByRole('checkbox', { name: 'Recordar mi correo' })).not.toBeChecked();
    expect(screen.getByLabelText('Correo')).toHaveFocus();
});

it('marca los campos para que el navegador guarde y rellene la contraseña', () => {
    render(<Login />);

    expect(screen.getByLabelText('Correo')).toHaveAttribute('autocomplete', 'username');
    expect(screen.getByLabelText('Contraseña')).toHaveAttribute('autocomplete', 'current-password');
});
