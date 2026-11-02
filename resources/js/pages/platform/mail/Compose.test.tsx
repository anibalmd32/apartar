import MailCompose from '@/pages/platform/mail/Compose';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

let url = '/plataforma/correos/redactar';

vi.mock('@/layouts/platform-layout', () => ({
    default: ({ back, children }: { back?: ReactNode; children: ReactNode }) => (
        <main>
            {back}
            {children}
        </main>
    ),
}));
vi.mock('@/components/ui/rich-text-field', () => ({ RichTextField: ({ label }: { label: string }) => <p>{label}</p> }));
vi.mock('@/hooks/use-leave-guard', () => ({ useLeaveGuard: () => null }));
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    usePage: () => ({ url, props: {} }),
    Link: ({ children, ...rest }: { children: ReactNode } & Record<string, unknown>) => <a {...rest}>{children}</a>,
}));

const defaults = { to: '', cc: '', subject: '' };

it('las direcciones abren el teclado de correo sin mayúsculas y la copia se añade a pedido', () => {
    render(<MailCompose mode="new" modeLabel="Correo nuevo" from="info@apartar.app" source={null} defaults={defaults} />);

    const to = screen.getByLabelText('Para');

    expect(to).toHaveAttribute('inputmode', 'email');
    expect(to).toHaveAttribute('autocapitalize', 'off');
    expect(screen.queryByLabelText('Copia')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Añadir copia' }));

    expect(screen.getByLabelText('Copia')).toHaveAttribute('inputmode', 'email');
});

it('vuelve a la pestaña de correos desde la que se abrió', () => {
    url = '/plataforma/correos/redactar?volver=%2Fplataforma%2Fcorreos%2Fenviados%3Fpagina%3D2';
    render(<MailCompose mode="new" modeLabel="Correo nuevo" from="info@apartar.app" source={null} defaults={defaults} />);

    expect(screen.getByRole('link', { name: 'Enviados' })).toHaveAttribute('href', '/plataforma/correos/enviados?pagina=2');
});

it('no vuelve a una dirección fuera de los correos', () => {
    url = '/plataforma/correos/redactar?volver=https%3A%2F%2Fevil.test';
    render(<MailCompose mode="new" modeLabel="Correo nuevo" from="info@apartar.app" source={null} defaults={defaults} />);

    expect(screen.getByRole('link', { name: 'Bandeja' })).toHaveAttribute('href', '/plataforma/correos/bandeja');
});

it('Enviar queda fijo abajo aunque el correo esté vacío', () => {
    url = '/plataforma/correos/redactar';
    render(<MailCompose mode="new" modeLabel="Correo nuevo" from="info@apartar.app" source={null} defaults={defaults} />);

    expect(screen.getByRole('button', { name: 'Enviar' }).closest('[data-form-action-bar]')).toHaveAttribute('data-sticky');
    expect(screen.getByRole('button', { name: 'Enviar' })).toHaveClass('max-lg:min-h-11');
});
