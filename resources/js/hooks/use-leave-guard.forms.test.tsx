import { PlatformSettingsForm } from '@/features/platform/components/PlatformSettingsForm';
import NotificationsCompose from '@/pages/backoffice/notifications/Compose';
import ProfileEdit from '@/pages/backoffice/profile/Edit';
import MailCompose from '@/pages/platform/mail/Compose';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { type ReactElement, type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

type BeforeListener = (event: { detail: { visit: Record<string, unknown> }; preventDefault: () => void }) => void;

const { listeners } = vi.hoisted(() => ({ listeners: [] as BeforeListener[] }));

vi.mock('@inertiajs/react', async (original) => {
    const actual = await original<{ router: object }>();

    return {
        ...actual,
        usePage: () => ({ url: '/', props: { errors: {}, teamMember: { organization: { name: 'Moda Valentina' } } } }),
        router: {
            ...actual.router,
            on: (_name: string, listener: BeforeListener) => {
                listeners.push(listener);

                return () => listeners.splice(listeners.indexOf(listener), 1);
            },
        },
    };
});

vi.mock('@/lib/json', () => ({
    JsonRequestError: class extends Error {},
    getJson: vi.fn(() => Promise.resolve({ customers: [] })),
    postJson: vi.fn(),
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/layouts/platform-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

beforeEach(() => {
    listeners.length = 0;
});

function leave() {
    const preventDefault = vi.fn();
    const visit = { method: 'get', only: [], prefetch: false, url: new URL('http://localhost/backoffice') };

    act(() => listeners.forEach((listener) => listener({ detail: { visit }, preventDefault })));

    return preventDefault;
}

const pages: [string, () => ReactElement, string, string][] = [
    [
        'el perfil',
        () => <ProfileEdit profile={{ name: 'Valentina Rojas', email: 'valentina@apartar.test', emailVerified: true, businesses: 1 }} />,
        'Nombre y apellido',
        '¿Salir sin guardar?',
    ],
    [
        'el aviso a clientes',
        () => (
            <NotificationsCompose
                available
                limits={{ title: 60, body: 180 }}
                previewUrl="/vista-previa"
                sendUrl="/avisos"
                recipientsUrl="/clientes"
                preselected={null}
                hourlyLimit={3}
                history={[]}
            />
        ),
        'Título',
        '¿Salir sin enviar?',
    ],
    [
        'los parámetros de la plataforma',
        () => <PlatformSettingsForm settings={[{ key: 'grace', label: 'Días de gracia', unit: 'días', value: 3, default: 3, min: 0, max: 30 }]} />,
        'Días de gracia',
        '¿Salir sin guardar?',
    ],
    [
        'el correo de la plataforma',
        () => <MailCompose mode="new" modeLabel="Nuevo correo" from="hola@apartar.app" source={null} defaults={{ to: '', cc: '', subject: '' }} />,
        'Asunto',
        '¿Salir sin enviar?',
    ],
];

it.each(pages)('%s pide confirmar antes de salir con cambios sin guardar', (_name, page, field, title) => {
    render(page());

    expect(leave()).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(new RegExp(`^${field}`)), { target: { value: '7' } });

    expect(leave()).toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: title })).toBeInTheDocument();
});
