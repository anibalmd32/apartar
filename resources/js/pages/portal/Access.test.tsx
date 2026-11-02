import Access from '@/pages/portal/Access';
import { fakeRoute } from '@/test/fakes';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { useForm, post, setData } = vi.hoisted(() => ({ useForm: vi.fn(), post: vi.fn(), setData: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({
    useForm,
    usePage: () => ({ props: {} }),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

const GOOGLE = '/cuenta/google/redirect?luego=%2Fmi-cuenta';

function mockForm(errors: Record<string, string> = {}) {
    useForm.mockReturnValue({ data: { document: '', code: '', email: '' }, setData, post, processing: false, errors, reset: vi.fn() });
}

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    setData.mockReset();
    mockForm();
});

it('pide la cédula y el código, y envía al acceso por código', () => {
    render(<Access googleUrl={GOOGLE} />);

    fireEvent.change(screen.getByLabelText('Cédula o RIF'), { target: { value: 'V-12.345.678' } });
    fireEvent.change(screen.getByLabelText('Código de apartado'), { target: { value: 'ap-7k3m9qrt' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ver mi apartado' }));

    expect(setData).toHaveBeenCalledWith('document', 'V12345678');
    expect(setData).toHaveBeenCalledWith('code', 'ap-7k3m9qrt');
    expect(post).toHaveBeenCalledWith('/mi-cuenta/acceso/codigo');
});

it('el código se escribe en mayúsculas, sin corrector, y la cédula no abre el teclado sola en el celular', () => {
    render(<Access googleUrl={GOOGLE} />);

    expect(screen.getByRole('tab', { name: 'Con código' })).toHaveAttribute('aria-selected', 'true');
    const code = screen.getByLabelText('Código de apartado');
    expect(code).toHaveAttribute('autocapitalize', 'characters');
    expect(code).toHaveAttribute('spellcheck', 'false');
    expect(screen.getByLabelText('Cédula o RIF')).not.toHaveFocus();
});

it('pinta el error genérico en el campo del código', () => {
    mockForm({ code: 'No encontramos un apartado con esa cédula y ese código.' });

    render(<Access googleUrl={GOOGLE} />);

    expect(screen.getByLabelText('Código de apartado')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('No encontramos un apartado con esa cédula y ese código.')).toBeInTheDocument();
});

it('dice dónde está el código', () => {
    render(<Access googleUrl={GOOGLE} />);

    expect(screen.getByText(/pídeselo al negocio donde apartaste/)).toBeInTheDocument();
});

it('ofrece pedir un enlace por correo con la cédula y el correo', () => {
    render(<Access googleUrl={GOOGLE} />);

    fireEvent.click(screen.getByRole('tab', { name: 'Por correo' }));
    fireEvent.change(screen.getByLabelText('Correo que dejaste'), { target: { value: 'maria@ejemplo.test' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviarme un enlace' }));

    expect(setData).toHaveBeenCalledWith('email', 'maria@ejemplo.test');
    expect(post).toHaveBeenCalledWith('/mi-cuenta/acceso/enlace', expect.objectContaining({ onSuccess: expect.any(Function) }));
});

it('pinta el aviso de la solicitud', () => {
    render(<Access googleUrl={GOOGLE} status="Si esos datos coinciden con un apartado que dejó ese correo, te enviamos un enlace." />);

    expect(screen.getByText(/te enviamos un enlace/)).toBeInTheDocument();
});

it('ofrece entrar con Google', () => {
    render(<Access googleUrl={GOOGLE} />);

    expect(screen.getByRole('link', { name: 'Entrar con Google' })).toHaveAttribute('href', GOOGLE);
});

it('desde el tercer paso llega con el código puesto y promete el pago', () => {
    render(<Access googleUrl={GOOGLE} code="AP-7K3M9QRT" />);

    expect(useForm).toHaveBeenCalledWith(expect.objectContaining({ code: 'AP-7K3M9QRT' }));
    expect(screen.getByRole('heading', { name: 'Paga tu apartado' })).toBeInTheDocument();
    expect(screen.getByText(/el código AP-7K3M9QRT ya va puesto/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continuar al pago' })).toBeInTheDocument();
});

it('al venir a pagar un apartado recién hecho, dice de qué negocio es y deja Google en segundo plano', () => {
    render(<Access googleUrl={GOOGLE} code="AP-7K3M9QRT" businessName="Ferretería Oriente" />);

    expect(screen.getByText('Tu apartado en Ferretería Oriente')).toBeInTheDocument();

    const google = screen.getByRole('link', { name: /Entrar con Google/ });
    const submit = screen.getByRole('button', { name: 'Continuar al pago' });
    expect(submit.compareDocumentPosition(google) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(google).not.toHaveClass('btn-lg');
});

it('sin código, Google sigue siendo la primera opción', () => {
    render(<Access googleUrl={GOOGLE} />);

    const google = screen.getByRole('link', { name: /Entrar con Google/ });
    const submit = screen.getByRole('button', { name: 'Ver mi apartado' });
    expect(google.compareDocumentPosition(submit) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
});
