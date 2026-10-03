import Access from '@/pages/portal/Access';
import { fakeRoute } from '@/test/fakes';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * El acceso al portal con cédula y código (`REQ-IDN-008`).
 */

const { useForm, post, setData } = vi.hoisted(() => ({ useForm: vi.fn(), post: vi.fn(), setData: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ useForm, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

function mockForm(errors: Record<string, string> = {}) {
    useForm.mockReturnValue({ data: { document: '', code: '', email: '' }, setData, post, processing: false, errors, reset: vi.fn() });
}

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    setData.mockReset();
    mockForm();
});

/** RB-2 · los dos datos, cada uno con su campo. */
it('pide la cédula y el código, y envía al acceso por código', () => {
    render(<Access />);

    fireEvent.change(screen.getByLabelText('Cédula o RIF'), { target: { value: 'V-12.345.678' } });
    fireEvent.change(screen.getByLabelText('Código de apartado'), { target: { value: 'ap-7k3m9qrt' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ver mi apartado' }));

    expect(setData).toHaveBeenCalledWith('document', 'V-12.345.678');
    expect(setData).toHaveBeenCalledWith('code', 'ap-7k3m9qrt');
    expect(post).toHaveBeenCalledWith('/mi-cuenta/acceso/codigo');
});

/** RB-5 · el error genérico llega en el campo del código, y se pinta ahí. */
it('pinta el error genérico en el campo del código', () => {
    mockForm({ code: 'No encontramos un apartado con esa cédula y ese código.' });

    render(<Access />);

    expect(screen.getByLabelText('Código de apartado')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('No encontramos un apartado con esa cédula y ese código.')).toBeInTheDocument();
});

/** SP-5 · quien perdió el código sabe dónde buscarlo. */
it('dice dónde está el código', () => {
    render(<Access />);

    expect(screen.getByText(/pídeselo al negocio donde apartaste/)).toBeInTheDocument();
});

/** `REQ-IDN-003` · la segunda vía: cédula y correo, hacia el envío del enlace. */
it('ofrece pedir un enlace por correo con la cédula y el correo', () => {
    render(<Access />);

    fireEvent.change(screen.getByLabelText('Correo que dejaste'), { target: { value: 'maria@ejemplo.test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviarme un enlace' }));

    expect(setData).toHaveBeenCalledWith('email', 'maria@ejemplo.test');
    expect(post).toHaveBeenCalledWith('/mi-cuenta/acceso/enlace', expect.objectContaining({ onSuccess: expect.any(Function) }));
});

/** RB-2 · la respuesta a la solicitud llega como aviso, sin decir si se envió. */
it('pinta el aviso de la solicitud', () => {
    render(<Access status="Si esos datos coinciden con un apartado que dejó ese correo, te enviamos un enlace." />);

    expect(screen.getByText(/te enviamos un enlace/)).toBeInTheDocument();
});
