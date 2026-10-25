import { Button } from '@/components/ui/button';
import { JsonRequestError, postJson } from '@/lib/json';
import { type CatalogChatState } from '@/types/chat';
import { useRef, useState } from 'react';

const MAX = 2000;

export function ChatWidget({ chat }: { chat: CatalogChatState }) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle');
    const [error, setError] = useState<string | null>(null);
    const key = useRef(crypto.randomUUID());

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
            <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
                Escribir al negocio
            </Button>
        );
    }

    if (!chat.canWrite) {
        return (
            <p className="bg-base-100 rounded-box p-3 text-sm" role="status">
                Para escribirle a este negocio, aparta algo o entra con tu cédula y el código de tu apartado.
            </p>
        );
    }

    return (
        <form
            className="bg-base-100 rounded-box flex flex-col gap-2 p-3"
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
    );
}
