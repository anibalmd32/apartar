import { DailyChart } from '@/features/platform/mail/DailyChart';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

const day = (date: string, sent: number, clicked = 0) => ({ date, sent, delivered: sent, opened: 0, clicked, bounced: 0 });

it('las series se reparten en varias líneas en vez de cortarse al deslizar', () => {
    render(<DailyChart daily={[day('2026-10-01', 2), day('2026-10-02', 3)]} />);

    const group = screen.getByRole('group', { name: 'Serie' });

    expect(group).toHaveClass('flex-wrap');
    expect(screen.getByRole('button', { name: 'Con clic' })).toBeInTheDocument();
});

it('sin datos en la serie lo dice en vez de dibujar barras vacías', () => {
    render(<DailyChart daily={[day('2026-10-01', 2), day('2026-10-02', 3)]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Con clic' }));

    expect(screen.getByText('Ningún clic en estas fechas.')).toBeInTheDocument();
    expect(screen.queryByText('Con clic por día')).not.toBeInTheDocument();
});

it('con un solo día con envíos avisa que hay pocos datos', () => {
    render(<DailyChart daily={[day('2026-10-01', 0), day('2026-10-02', 3)]} />);

    expect(screen.getByText(/pocos datos/)).toBeInTheDocument();
});
