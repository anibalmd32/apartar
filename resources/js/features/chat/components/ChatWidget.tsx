import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { JsonRequestError, postJson } from '@/lib/json';
import { cn } from '@/lib/utils';
import { type CatalogChatState } from '@/types/chat';
import { cilChatBubble, cilX } from '@coreui/icons';
import { type ReactNode, useEffect, useRef, useState } from 'react';

const MAX = 2000;
const COLLAPSE_AFTER = 120;

function useScrolledPast(offset: number): boolean {
    const [past, setPast] = useState(false);

    useEffect(() => {
        const update = (): void => setPast(window.scrollY > offset);
        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
    }, [offset]);

    return past;
}

function Panel({ onClose, children }: { onClose: () => void; children: ReactNode }) {
    return (
        <div className="bg-base-100 border-line shadow-pop rounded-box flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2 border p-3">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-bold">Escribir al negocio</p>
                <button type="button" className="btn btn-ghost btn-sm btn-square" aria-label="Cerrar" onClick={onClose}>
                    <Icon icon={cilX} size={14} />
                </button>
            </div>
            {children}
        </div>
    );
}

export function ChatWidget({ chat }: { chat: CatalogChatState }) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle');
    const [error, setError] = useState<string | null>(null);
    const key = useRef(crypto.randomUUID());
    const collapsed = useScrolledPast(COLLAPSE_AFTER);

    const send = async () => {
        const body = draft.trim();

        if (body === '' || body.length > MAX) {
            return;
        }

        setState('sending');

        try {
            await postJson(chat.sendUrl, { body, idempotencyKey: key.current });
            key.current = crypto.randomUUID();
            setDraft('');
            setState('sent');
        } catch (failure) {
            setError(failure instanceof JsonRequestError ? failure.message : 'No se pudo enviar. Revisa tu conexión.');
            setState('failed');
        }
    };

    if (!open) {
        return (
            <button
                type="button"
                aria-label="Escribir al negocio"
                data-collapsed={collapsed || undefined}
                onClick={() => setOpen(true)}
                className={cn(
                    'btn bg-neutral text-neutral-content shadow-pop h-14 min-w-14 rounded-full border-0 px-0 text-sm font-bold transition-[padding] duration-200',
                    !collapsed && 'px-5',
                )}
            >
                <Icon icon={cilChatBubble} size={20} />
                <span aria-hidden="true" className={cn(collapsed && 'sr-only')}>
                    Escribir al negocio
                </span>
            </button>
        );
    }

    if (!chat.canWrite) {
        return (
            <Panel onClose={() => setOpen(false)}>
                <p className="text-ink-2 text-sm" role="status">
                    Para escribirle a este negocio, aparta algo o entra con tu cédula y el código de tu apartado.
                </p>
            </Panel>
        );
    }

    return (
        <Panel onClose={() => setOpen(false)}>
            <form
                className="flex flex-col gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void send();
                }}
            >
                <textarea
                    className="textarea textarea-bordered w-full"
                    aria-label="Mensaje al negocio"
                    rows={3}
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                />
                {state === 'sent' ? (
                    <p className="text-sm" role="status">
                        Enviado.{' '}
                        <a href={chat.threadUrl} className="link">
                            Ver la conversación
                        </a>
                    </p>
                ) : null}
                {state === 'failed' && error !== null ? (
                    <p className="text-error text-sm" role="alert">
                        {error}
                    </p>
                ) : null}
                <Button type="submit" size="sm" loading={state === 'sending'} disabled={draft.trim() === '' || draft.length > MAX}>
                    {state === 'failed' ? 'Reintentar' : 'Enviar'}
                </Button>
            </form>
        </Panel>
    );
}

export function ChatDock({ chat }: { chat: CatalogChatState }) {
    return (
        <div
            data-chat-dock
            className="fixed right-4 bottom-[calc(var(--overlay-bottom)+var(--consent-height)+1rem)] z-30 flex flex-col items-end lg:right-10 lg:bottom-[calc(var(--consent-height)+2rem)]"
        >
            <ChatWidget chat={chat} />
        </div>
    );
}
