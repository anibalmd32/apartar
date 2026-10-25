import VerifyEmail from '@/pages/auth/verify-email';
import { act, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { email: 'ana@moda.test' } } } }),
    useForm: () => ({ post: vi.fn(), processing: false }),
    Link: ({ children }: { children: ReactNode }) => <button type="button">{children}</button>,
}));

vi.mock('@/layouts/backoffice/access-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('cuenta 60 segundos antes de dejar reenviar', () => {
    render(<VerifyEmail />);

    expect(screen.getByRole('button', { name: 'Reenviar correo' })).toBeDisabled();
    expect(screen.getByText(/Puedes reenviarlo en 60 s\./)).toBeInTheDocument();

    act(() => vi.advanceTimersByTime(59_000));
    expect(screen.getByText(/Puedes reenviarlo en 1 s\./)).toBeInTheDocument();

    act(() => vi.advanceTimersByTime(1_000));
    expect(screen.getByRole('button', { name: 'Reenviar correo' })).toBeEnabled();
    expect(screen.queryByText(/Puedes reenviarlo/)).not.toBeInTheDocument();
});
