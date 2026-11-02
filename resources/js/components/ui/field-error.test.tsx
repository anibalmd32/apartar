import { CheckboxField } from '@/components/ui/checkbox-field';
import { IconTextField } from '@/components/ui/icon-text-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { render, screen } from '@testing-library/react';
import type { ReactElement } from 'react';
import { describe, expect, it } from 'vitest';

const fields: Array<[string, ReactElement]> = [
    ['TextField', <TextField key="text" label="Nombre" error="Completa el campo nombre." hint="Como aparece en la cédula." />],
    ['IconTextField', <IconTextField key="icon" label="Correo" error="Completa el campo nombre." hint="Como aparece en la cédula." />],
    [
        'SelectField',
        <SelectField
            key="select"
            label="Banco"
            options={[{ value: '1', label: 'Banesco' }]}
            error="Completa el campo nombre."
            hint="Como aparece en la cédula."
        />,
    ],
    ['CheckboxField', <CheckboxField key="checkbox" label="Acepto" error="Completa el campo nombre." hint="Como aparece en la cédula." />],
];

describe('errores de los campos', () => {
    it.each(fields)('%s muestra el error en rojo, con icono y distinto de la ayuda', (_name, field) => {
        render(field);

        const error = screen.getByRole('alert');
        const hint = screen.getByText('Como aparece en la cédula.');

        expect(error).toHaveTextContent('Completa el campo nombre.');
        expect(error).toHaveClass('text-error');
        expect(error).not.toHaveClass('label');
        expect(error.querySelector('svg')).not.toBeNull();
        expect(hint).not.toHaveClass('text-error');
        expect(screen.getByLabelText(/Nombre|Correo|Banco|Acepto/)).toHaveAttribute('aria-describedby', expect.stringContaining(error.id));
    });
});
