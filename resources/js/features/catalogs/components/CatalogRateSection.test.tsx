import { CatalogRateSection } from '@/features/catalogs/components/CatalogRateSection';
import { type RateSourceChoices } from '@/types/exchange';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
}));

const CHOICES: RateSourceChoices = {
    options: [
        { value: '', label: 'La del negocio (BCV)' },
        { value: 'bcv', label: 'BCV' },
        { value: 'parallel', label: 'Paralelo' },
        { value: 'manual', label: 'Tasa propia' },
    ],
    businessSource: 'bcv',
    hasManualRate: true,
};

function renderSection(safeguard: boolean, onSafeguardChange = vi.fn()) {
    render(
        <CatalogRateSection
            choices={CHOICES}
            rateSource=""
            onRateSourceChange={vi.fn()}
            safeguard={safeguard}
            onSafeguardChange={onSafeguardChange}
        />,
    );

    return onSafeguardChange;
}

it('ofrece activar el resguardo en dólar paralelo sin aviso mientras está apagado', () => {
    const change = renderSection(false);

    fireEvent.click(screen.getByLabelText('Activar resguardo en dólar paralelo'));

    expect(change).toHaveBeenCalledWith(true);
    expect(screen.queryByRole('alert')).toBeNull();
});

it('al activarlo explica, en tono de advertencia, que el cliente sigue pagando con la tasa del catálogo', () => {
    renderSection(true);

    const alert = screen.getByRole('alert');
    expect(alert).toHaveClass('alert-warning');
    expect(alert).toHaveTextContent('Tus clientes siguen pagando con la tasa de este catálogo (BCV).');
    expect(alert).toHaveTextContent('USDT, divisas en efectivo, Zelle u otro');
});
