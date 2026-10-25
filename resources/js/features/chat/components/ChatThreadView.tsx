import { Icon } from '@/components/ui/icon';
import { ChatBubble, DaySeparator } from '@/features/chat/components/ChatBubble';
import { useConversation } from '@/features/chat/hooks/useConversation';
import { useTypingIndicator } from '@/features/chat/hooks/useTypingIndicator';
import { dayKey, dayLabel } from '@/features/chat/lib/day-label';
import { cn } from '@/lib/utils';
import { type ChatThread, type MessageSenderType } from '@/types/chat';
import { cilSend } from '@coreui/icons';
import { Fragment, useEffect, useRef, useState, type FormEvent } from 'react';

export const MAX_MESSAGE_LENGTH = 2000;

const COUNTER_FROM = 200;

interface ChatThreadViewProps {
    thread: ChatThread;
    me: MessageSenderType;
    placeholder?: string;
    className?: string;
}

export function ChatThreadView({ thread, me, placeholder, className }: ChatThreadViewProps) {
    const conversation = useConversation(thread, me);
    const indicator = useTypingIndicator(thread.channel, thread.typingUrl, me, conversation.mode === 'live');
    const [draft, setDraft] = useState('');
    const remaining = MAX_MESSAGE_LENGTH - draft.length;
    const end = useRef<HTMLDivElement>(null);
    const count = conversation.messages.length + conversation.outgoing.length;
    const last = conversation.messages.at(-1);
    const lastDay = last === undefined ? null : dayKey(last.sentAt);

    useEffect(() => {
        end.current?.scrollIntoView?.({ block: 'end' });
    }, [count, indicator.typing]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (draft.trim() === '' || remaining < 0) {
            return;
        }

        conversation.send(draft);
        setDraft('');
    };

    return (
        <div className={cn('flex min-h-0 flex-col', className)}>
            <div className="flex min-h-0 flex-1 flex-col gap-3.5 overflow-y-auto px-4 py-5 lg:px-6">
                {conversation.mode === 'polling' ? (
                    <p className="text-ink-3 self-center text-center text-xs">
                        Modo sin conexión en vivo: los mensajes se actualizan cada pocos segundos.
                    </p>
                ) : null}

                <ol className="flex flex-col gap-3.5" aria-label="Mensajes">
                    {count === 0 ? <li className="text-ink-3 self-center py-6 text-sm">Todavía no hay mensajes. Escribe el primero.</li> : null}
                    {conversation.messages.map((message, index) => {
                        const previous = conversation.messages[index - 1];

                        return (
                            <Fragment key={message.id}>
                                {previous === undefined || dayKey(previous.sentAt) !== dayKey(message.sentAt) ? (
                                    <DaySeparator label={dayLabel(message.sentAt)} />
                                ) : null}
                                <ChatBubble
                                    mine={conversation.mine(message)}
                                    body={message.body}
                                    meta={
                                        <>
                                            {meta(message.senderName, message.sentAt)}
                                            {conversation.mine(message) && message.readAt ? ' · Leído' : null}
                                        </>
                                    }
                                />
                            </Fragment>
                        );
                    })}
                    {conversation.outgoing.length > 0 && lastDay !== dayKey(new Date()) ? <DaySeparator label="Hoy" /> : null}
                    {conversation.outgoing.map((item) => (
                        <ChatBubble
                            key={item.key}
                            mine
                            pending
                            body={item.body}
                            meta={
                                item.status === 'sending' ? (
                                    'Enviando…'
                                ) : (
                                    <span role="alert" className="text-error">
                                        {item.error} ·{' '}
                                        <button type="button" className="font-semibold underline" onClick={() => conversation.retry(item.key)}>
                                            Reintentar
                                        </button>
                                    </span>
                                )
                            }
                        />
                    ))}
                </ol>

                {indicator.typing !== null ? (
                    <p className="bg-surface-2 flex items-center gap-1 self-start rounded-2xl px-3.5 py-2.5" role="status">
                        <span aria-hidden="true" className="flex gap-1">
                            <span className="bg-ink-3 size-1.5 animate-pulse rounded-full" />
                            <span className="bg-ink-3 size-1.5 animate-pulse rounded-full [animation-delay:150ms]" />
                            <span className="bg-ink-3 size-1.5 animate-pulse rounded-full [animation-delay:300ms]" />
                        </span>
                        <span className="sr-only">{indicator.typing} está escribiendo…</span>
                    </p>
                ) : null}
                <div ref={end} />
            </div>

            <form className="border-line flex items-end gap-2.5 border-t px-4 py-3 lg:px-5" onSubmit={submit}>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <textarea
                        className="bg-surface-2 placeholder:text-ink-3 focus:outline-primary min-h-11 w-full resize-none rounded-[22px] px-4 py-2.5 text-sm leading-normal focus:outline-2"
                        aria-label="Mensaje"
                        rows={1}
                        value={draft}
                        placeholder={placeholder ?? 'Escribe tu mensaje'}
                        onChange={(event) => {
                            setDraft(event.target.value);
                            indicator.notify();
                        }}
                    />
                    {remaining < COUNTER_FROM ? (
                        <span className={cn('px-4 text-xs', remaining < 0 ? 'text-error' : 'text-ink-3')}>{remaining}</span>
                    ) : null}
                </div>
                <button
                    type="submit"
                    className="btn btn-primary btn-circle size-11 shrink-0"
                    disabled={draft.trim() === '' || remaining < 0}
                    aria-label="Enviar"
                >
                    <Icon icon={cilSend} size={18} />
                </button>
            </form>
        </div>
    );
}

function meta(senderName: string, sentAt: string): string {
    const time = new Intl.DateTimeFormat('es-VE', { hour: '2-digit', minute: '2-digit' }).format(new Date(sentAt));

    return `${senderName} · ${time}`;
}
