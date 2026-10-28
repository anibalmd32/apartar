import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { ThreadMessage } from '@/features/platform/mail/ThreadMessage';
import PlatformLayout from '@/layouts/platform-layout';
import { type ConversationMessage } from '@/types/mail';
import { cilArrowLeft, cilEnvelopeClosed } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';

interface ThreadProps {
    thread: { id: number; subject: string; counterpart: string };
    messages: readonly ConversationMessage[];
}

export default function MailThreadPage({ thread, messages }: ThreadProps) {
    const lastInbound = [...messages].reverse().find((message) => message.kind === 'inbound');

    return (
        <PlatformLayout
            title={thread.subject}
            description={`Conversación con ${thread.counterpart}`}
            back={
                <Link
                    href="/plataforma/correos/bandeja"
                    className="text-ink-2 hover:text-base-content inline-flex items-center gap-1.5 self-start text-[13px]"
                >
                    <Icon icon={cilArrowLeft} size={14} />
                    Bandeja
                </Link>
            }
            actions={
                <>
                    {lastInbound ? (
                        <Button variant="secondary" size="sm" onClick={() => router.post(`/plataforma/correos/bandeja/${thread.id}/no-leido`)}>
                            <Icon icon={cilEnvelopeClosed} size={14} />
                            Marcar como no leída
                        </Button>
                    ) : null}
                    {lastInbound && lastInbound.kind === 'inbound' ? (
                        <Link href={`/plataforma/correos/redactar?responder=${lastInbound.id}&modo=responder`} className="btn btn-primary btn-sm">
                            Responder
                        </Link>
                    ) : null}
                </>
            }
        >
            <ol className="flex flex-col gap-3">
                {messages.map((message, index) => (
                    <li key={message.key}>
                        <ThreadMessage message={message} open={index === messages.length - 1 || (message.kind === 'inbound' && message.unread)} />
                    </li>
                ))}
            </ol>
        </PlatformLayout>
    );
}
