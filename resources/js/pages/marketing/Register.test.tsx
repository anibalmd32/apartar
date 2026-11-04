import Register from '@/pages/marketing/Register';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const form = vi.hoisted(() => ({ post: vi.fn(), reset: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        setData: vi.fn(),
        post: form.post,
        processing: false,
        errors: {},
        reset: form.reset,
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

it('con un plan elegido en los precios dice en una sola frase que empieza gratis y luego pasa a ese plan', () => {
    render(<Register plan={{ code: 'pro', name: 'Pro' }} />);

    expect(screen.getByText(/empieza en el plan Gratis, sin tarjeta. Después te llevamos a Suscripción para pasar a Pro/)).toBeInTheDocument();
    expect(screen.queryByText(/Empiezas en el plan Gratis/)).not.toBeInTheDocument();
});

it('la ayuda de la contraseña empieza con mayúscula', () => {
    render(<Register plan={null} />);

    expect(screen.getByText('Mínimo 6 caracteres, con letras y números.')).toBeInTheDocument();
});

it('un error que no es de la contraseña no borra las contraseñas ya escritas', () => {
    render(<Register plan={null} />);

    fireEvent.submit(screen.getByRole('button', { name: 'Crear mi cuenta gratis' }).closest('form')!);

    const options = form.post.mock.calls.at(-1)?.[1] as { onError: (errors: Record<string, string>) => void };

    options.onError({ owner_name: 'Completa el campo nombre.' });
    expect(form.reset).not.toHaveBeenCalled();

    options.onError({ owner_password: 'La confirmación no coincide.' });
    expect(form.reset).toHaveBeenCalledWith('owner_password', 'owner_password_confirmation');
});
