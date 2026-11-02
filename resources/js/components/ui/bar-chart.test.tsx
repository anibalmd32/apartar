import { BarChart, tickVisibility } from '@/components/ui/bar-chart';
import { render } from '@testing-library/react';
import { expect, it } from 'vitest';

const days = Array.from({ length: 30 }, (_, index) => ({ label: `${index + 1} oct`, value: index }));

it('en 30 días rotula cada 5 días y el último, sin recortar las etiquetas', () => {
    const { container } = render(<BarChart label="Enviados" data={days} />);
    const ticks = Array.from(container.querySelectorAll('[data-chart-ticks] > span'));
    const visible = ticks.filter((tick) => !tick.classList.contains('invisible')).map((tick) => tick.textContent);

    expect(visible).toEqual(['1 oct', '6 oct', '11 oct', '16 oct', '21 oct', '30 oct']);
    ticks.forEach((tick) => expect(tick).not.toHaveClass('truncate'));
});

it('en doce meses el celular muestra uno de cada dos y la computadora todos', () => {
    const shown = Array.from({ length: 12 }, (_, index) => tickVisibility(index, 12));

    expect(shown).toEqual(['all', 'desktop', 'all', 'desktop', 'all', 'desktop', 'all', 'desktop', 'all', 'desktop', 'desktop', 'all']);
});

it('es más bajo en el celular que en la computadora', () => {
    const { container } = render(<BarChart label="Meses" data={days.slice(0, 3)} />);

    expect(container.querySelector('[aria-hidden="true"]')).toHaveClass('h-[120px]', 'lg:h-[160px]');
});
