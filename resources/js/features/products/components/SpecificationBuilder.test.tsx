import { SpecificationBuilder } from '@/features/products/components/SpecificationBuilder';
import { MAX_SPECIFICATIONS, useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { type ProductFormValues } from '@/types/product';
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

function withSpecifications(specifications: string[]): ProductFormValues {
    return { specifications } as unknown as ProductFormValues;
}

function Harness({ initial = [], errors = {} }: { initial?: string[]; errors?: Partial<Record<string, string>> }) {
    const sheet = useSpecificationRows(withSpecifications(initial));

    return <SpecificationBuilder rows={sheet} errors={errors} />;
}

it('muestra una caja de texto por característica, en orden', () => {
    render(<Harness initial={['Pantalla de 6,5 pulgadas', 'Batería de 5000 mAh']} />);

    expect(screen.getByLabelText('Característica 1')).toHaveValue('Pantalla de 6,5 pulgadas');
    expect(screen.getByLabelText('Característica 2')).toHaveValue('Batería de 5000 mAh');
    expect(screen.getAllByPlaceholderText('Ej.: Pantalla de 6,5 pulgadas')).toHaveLength(2);
});

it('Intro añade una fila vacía justo debajo, le pasa el foco y no envía el formulario', () => {
    const onSubmit = vi.fn((event: SubmitEvent) => event.preventDefault());
    render(
        <form onSubmit={(event) => onSubmit(event.nativeEvent as SubmitEvent)}>
            <Harness initial={['Primera', 'Tercera']} />
        </form>,
    );

    fireEvent.keyDown(screen.getByLabelText('Característica 1'), { key: 'Enter' });

    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Característica 2')).toHaveValue('');
    expect(screen.getByLabelText('Característica 2')).toHaveFocus();
    expect(screen.getByLabelText('Característica 3')).toHaveValue('Tercera');
});

it('«Agregar característica» añade una fila al final y la quita el botón de borrar', () => {
    render(<Harness />);

    fireEvent.click(screen.getByRole('button', { name: /Agregar característica/ }));
    expect(screen.getByLabelText('Característica 1')).toHaveFocus();

    fireEvent.click(screen.getByRole('button', { name: 'Quitar la característica 1' }));
    expect(screen.queryByLabelText('Característica 1')).toBeNull();
});

it('avisa de una característica repetida y muestra el error del servidor en su fila', () => {
    render(<Harness initial={['Cuerpo de acero', 'cuerpo  de ACERO', 'Otra']} errors={{ 'specifications.2': 'Demasiado larga' }} />);

    const alerts = screen.getAllByRole('alert').map((alert) => alert.textContent);

    expect(alerts).toEqual(['Esta característica ya está en la lista.', 'Demasiado larga']);
});

it('replaceAll sustituye la lista y append añade sin repetir ni dejar huecos', () => {
    const { result } = renderHook(() => useSpecificationRows(withSpecifications(['Pantalla de 6,5 pulgadas', ''])));

    act(() => result.current.append(['  batería de   5000 mAh ', 'PANTALLA DE 6,5 PULGADAS', '']));
    expect(result.current.payload()).toEqual(['Pantalla de 6,5 pulgadas', 'batería de 5000 mAh']);

    act(() => result.current.replaceAll(['Cámara de 50 MP', 'Cámara de 50 MP', 'Carga rápida']));
    expect(result.current.payload()).toEqual(['Cámara de 50 MP', 'Carga rápida']);
});

it('append respeta el tope de características', () => {
    const { result } = renderHook(() => useSpecificationRows(null));

    act(() => result.current.append(Array.from({ length: MAX_SPECIFICATIONS + 5 }, (_, index) => `Característica ${index + 1}`)));

    expect(result.current.rows).toHaveLength(MAX_SPECIFICATIONS);
});
