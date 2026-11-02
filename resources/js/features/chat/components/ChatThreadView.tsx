import { ChatBubble, DaySeparator } from '@/features/chat/components/ChatBubble';
import { ChatComposer } from '@/features/chat/components/ChatComposer';
import { useConversation } from '@/features/chat/hooks/useConversation';
import { useTypingIndicator } from '@/features/chat/hooks/useTypingIndicator';
import { dayKey, dayLabel } from '@/features/chat/lib/day-label';
import { placeMessages } from '@/features/chat/lib/group-messages';
import { formatTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type ChatThread, type MessageSenderType } from '@/types/chat';
import { Fragment, useEffect, useMemo, useRef } from 'react';

interface ChatThreadViewProps {
    thread: ChatThread;
    me: MessageSenderType;
    placeholder?: string;
    className?: string;
}

export function ChatThreadView({ thread, me, placeholder, className }: ChatThreadViewProps) {
    const conversation = useConversation(thread, me);
    const indicator = useTypingIndicator(thread.channel, thread.typingUrl, me, conversation.mode === 'live');
    const end = useRef<HTMLDivElement>(null);
    const count = conversation.messages.length + conversation.outgoing.length;
    const last = conversation.messages.at(-1);
    const lastDay = last === undefined ? null : dayKey(last.sentAt);
    const placements = useMemo(() => placeMessages(conversation.messages), [conversation.messages]);

    useEffect(() => {
        end.current?.scrollIntoView?.({ block: 'end' });
    }, [count, indicator.typing]);

    return (
        <div className={cn('flex min-h-0 flex-col', className)}>
            <div className="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain px-4 py-4 lg:px-6 lg:py-5">
                <ol className="flex flex-col gap-1" aria-label="Mensajes">
                    {count === 0 ? <li className="text-ink-3 self-center py-6 text-sm">Todavía no hay mensajes. Escribe el primero.</li> : null}
                    {conversation.messages.map((message, index) => {
                        const place = placements[index];
                        const mine = conversation.mine(message);

                        return (
                            <Fragment key={message.id}>
                                {place?.newDay ? <DaySeparator label={dayLabel(message.sentAt)} /> : null}
                                <ChatBubble
                                    mine={mine}
                                    body={message.body}
                                    startsGroup={place?.startsGroup ?? true}
                                    meta={
                                        place?.endsGroup === false ? null : (
                                            <>
                                                {`${message.senderName} · ${formatTime(message.sentAt)}`}
                                                {mine && message.readAt ? ' · Leído' : null}
                                            </>
                                        )
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
                    <p className="bg-surface-2 mt-3 flex items-center gap-1 self-start rounded-2xl px-3.5 py-2.5" role="status">
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

            <ChatComposer placeholder={placeholder} onSend={conversation.send} onType={indicator.notify} />
        </div>
    );
}
