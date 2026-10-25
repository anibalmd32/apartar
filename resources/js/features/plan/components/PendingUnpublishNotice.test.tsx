import { PendingUnpublishNotice } from '@/features/plan/components/PendingUnpublishNotice';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('dice cuántos catálogos sobran, desde cuándo y que se conservan los más antiguos', () => {
    render(<PendingUnpublishNotice pending={{ excess: 3, limit: 1, unpublishAt: '2026-11-08T02:00:00Z' }} timeZone="America/Caracas" />);

    expect(screen.getByText(/Tu plan permite 1 y tienes 3 catálogos publicados de más/)).toBeInTheDocument();
    expect(screen.getByText(/A partir del 07\/11\/2026/)).toBeInTheDocument();
    expect(screen.getByText(/se conservarán los más antiguos/)).toBeInTheDocument();
    expect(screen.getByText(/Nada se borra/)).toBeInTheDocument();
});

it('en singular cuando sobra uno', () => {
    render(<PendingUnpublishNotice pending={{ excess: 1, limit: 1, unpublishAt: '2026-11-08T04:05:00Z' }} timeZone="America/Caracas" />);

    expect(screen.getByText(/tienes 1 catálogo publicado de más/)).toBeInTheDocument();
});
