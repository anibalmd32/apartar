import Chat from '@/pages/portal/Chat';
import { type PortalChatProps } from '@/types/chat';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: { auth: { portal: { fullName: 'José Gregorio Martínez' } } } }),
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/layouts/portal-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

vi.mock('@/features/chat/components/ChatThreadView', () => ({ ChatThreadView: () => <textarea aria-label="Mensaje" /> }));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

it('en el celular el chat deja libre la barra inferior y el área segura para que el campo de escribir no quede tapado', () => {
    render(<Chat {...({ businessName: 'Moda Valentina', thread: {} } as unknown as PortalChatProps)} />);

    const section = screen.getByRole('heading', { name: 'Escríbele a Moda Valentina' }).closest('section')!;
    const mobileHeight = Array.from(section.classList).find((name) => name.startsWith('h-['));

    expect(mobileHeight).toContain('env(safe-area-inset-bottom)');
    expect(Number(/100dvh_-_(\d+)px/.exec(mobileHeight ?? '')?.[1])).toBeGreaterThanOrEqual(205);
    expect(section).toHaveClass('min-h-[360px]', 'lg:min-h-[480px]');
});
