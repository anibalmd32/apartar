import SettingsIndex from '@/pages/backoffice/settings/Index';
import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<object>()),
    usePage: () => ({ props: {} }),
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

afterEach(() => vi.restoreAllMocks());

function renderSettings() {
    return render(
        <SettingsIndex
            settings={{ exchange_rate_source: 'bcv', exchange_rate_manual: null, grace_period_days: 3, reminder_offsets_days: [1] }}
            definitions={[{ key: 'grace_period_days', label: 'Días de gracia', scope: 'immediate', scopeNote: 'Aplica ya.' }]}
            identity={{
                name: 'Moda Valentina',
                slug: 'moda-valentina',
                document: null,
                phone: null,
                timezone: 'America/Caracas',
                brandColor: null,
                storefrontEnabled: true,
            }}
            brand={{ name: 'Moda Valentina', slug: 'moda-valentina', logoUrl: null, brandColor: null, brandingEnabled: true }}
            rateSources={[]}
            rateSourceOptions={[{ value: 'bcv', label: 'BCV' }]}
        />,
    );
}

it('al cambiar la identidad, en el celular su botón de guardar queda fijo fuera de la sección para alcanzarlo sin volver arriba', () => {
    renderSettings();

    fireEvent.change(screen.getByLabelText(/Nombre del negocio/), { target: { value: 'Moda Valentina C.A.' } });

    const save = screen.getAllByRole('button', { name: 'Guardar identidad' }).find((button) => button.closest('section') === null);
    expect(save).toBeDefined();
    expect(save).toHaveAttribute('form', 'ajustes-identidad');
    expect(save?.parentElement).toHaveClass('max-lg:sticky', 'lg:hidden');
    expect(document.getElementById('ajustes-identidad')?.tagName).toBe('FORM');
});

it('la barra guarda la sección con cambios y desaparece cuando el guardado sale bien', () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
    renderSettings();

    fireEvent.change(screen.getByLabelText(/Nombre del negocio/), { target: { value: 'Moda Valentina C.A.' } });
    const bar = screen.getAllByRole('button', { name: 'Guardar identidad' }).find((button) => button.closest('section') === null);
    fireEvent.click(bar as HTMLElement);

    expect(visit).toHaveBeenCalledWith(expect.stringContaining('/ajustes/identidad'), expect.objectContaining({ method: 'post' }));

    const options = visit.mock.calls[0]?.[1] as { onSuccess: (page: unknown) => void };
    act(() => options.onSuccess({}));

    expect(screen.getAllByRole('button', { name: 'Guardar identidad' })).toHaveLength(1);
});
