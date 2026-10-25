import { SpecificationTable } from '@/features/catalog/components/SpecificationTable';
import { type SpecificationGroupView } from '@/types/product';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

function group(name: string | null, items: { label: string; value: string }[]): SpecificationGroupView {
    return { group: name, items };
}

describe('la ficha técnica', () => {
    it('pinta cada sección con sus pares', () => {
        render(
            <SpecificationTable
                groups={[
                    group('Motor', [
                        { label: 'Potencia', value: '750 W' },
                        { label: 'Velocidades', value: '2' },
                    ]),
                ]}
            />,
        );

        expect(screen.getByText('Motor')).toBeInTheDocument();
        expect(screen.getByText('Potencia')).toBeInTheDocument();
        expect(screen.getByText('750 W')).toBeInTheDocument();
        expect(screen.getByText('Velocidades')).toBeInTheDocument();
    });

    it('llama General a la sección sin nombre', () => {
        render(<SpecificationTable groups={[group(null, [{ label: 'Material', value: 'Acero' }])]} />);

        expect(screen.getByText('General')).toBeInTheDocument();
    });

    it('no reordena lo que le llega', () => {
        render(
            <SpecificationTable
                groups={[group(null, [{ label: 'Material', value: 'Acero' }]), group('Motor', [{ label: 'Potencia', value: '750 W' }])]}
            />,
        );

        const headings = screen.getAllByRole('heading').map((heading) => heading.textContent);

        expect(headings).toEqual(['General', 'Motor']);
    });

    it('no pinta nada cuando no hay especificaciones', () => {
        const { container } = render(<SpecificationTable groups={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('escapa el HTML del valor en lugar de ejecutarlo', () => {
        const attack = '<script>alert("xss")</script>';

        const { container } = render(<SpecificationTable groups={[group('Motor', [{ label: 'Potencia', value: attack }])]} />);

        expect(screen.getByText(attack)).toBeInTheDocument();
        expect(container.querySelector('script')).toBeNull();
        expect(container.innerHTML).not.toContain('<script>');
    });

    it('escapa también el HTML de la característica', () => {
        const attack = '<img src=x onerror="alert(1)">';

        const { container } = render(<SpecificationTable groups={[group(null, [{ label: attack, value: 'Acero' }])]} />);

        expect(screen.getByText(attack)).toBeInTheDocument();
        expect(container.querySelector('img')).toBeNull();
    });

    it('conserva los saltos de línea del valor', () => {
        const value = 'Alto: 120 cm\nAncho: 80 cm';

        render(<SpecificationTable groups={[group(null, [{ label: 'Medidas', value }])]} />);

        const rendered = screen.getByText(/Alto: 120 cm/);

        expect(rendered.textContent).toBe(value);
        expect(rendered).toHaveClass('whitespace-pre-line');
    });
});
