import Register from '@/pages/marketing/Register';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/features/marketing/hooks/useSlugAvailability', () => ({
    useSlugAvailability: () => ({ state: 'idle', suggestion: null }),
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

it('el registro enlaza los términos y la privacidad que se aceptan al crear la cuenta', () => {
    render(<Register plan={null} />);

    expect(screen.getByText(/Al crear tu cuenta aceptas/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Términos de servicio' })).toHaveAttribute('href', '/legal.terms');
    expect(screen.getByRole('link', { name: 'Política de privacidad' })).toHaveAttribute('href', '/legal.privacy');
});

it('el botón de crear la cuenta usa la forma primaria del sistema', () => {
    render(<Register plan={null} />);

    const submit = screen.getByRole('button', { name: 'Crear mi cuenta gratis' });

    expect(submit).toHaveClass('btn', 'btn-primary');
    expect(submit).not.toHaveClass('h-12');
});

it('avisa el plan elegido en los precios', () => {
    render(<Register plan={{ code: 'pro', name: 'Pro' }} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Elegiste Pro');
});

it('la ayuda de la contraseña empieza con mayúscula', () => {
    render(<Register plan={null} />);

    expect(screen.getByText('Mínimo 6 caracteres, con letras y números.')).toBeInTheDocument();
});
