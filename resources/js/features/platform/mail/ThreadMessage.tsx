import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type ConversationMessage } from '@/types/mail';
import { cilActionUndo, cilCloudDownload, cilPaperclip, cilShare } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { dateTime, fileSize } from './format';
import { MailFrame } from './MailFrame';

const REPLY_ACTIONS = [
    { mode: 'responder', label: 'Responder', icon: cilActionUndo },
    { mode: 'responder-todos', label: 'Responder a todos', icon: cilActionUndo },
    { mode: 'reenviar', label: 'Reenviar', icon: cilShare },
] as const;

export function ThreadMessage({ message, open }: { message: ConversationMessage; open: boolean }) {
    const inbound = message.kind === 'inbound';
    const recipients = [...message.to, ...message.cc];

    return (
        <details
            open={open}
            className={cn('border-line bg-base-100 shadow-card group rounded-2xl border', !inbound && 'border-l-primary border-l-4')}
        >
            <summary className="flex cursor-pointer list-none flex-col gap-0.5 px-5 py-3.5">
                <span className="flex flex-wrap items-center gap-2">
                    <span className={cn('text-sm', inbound && message.unread ? 'font-bold' : 'font-semibold')}>{message.from}</span>
                    {inbound ? null : <Badge tone="primary">{message.operator ? `Enviado por ${message.operator}` : 'Enviado'}</Badge>}
                    {inbound ? null : <span className="text-ink-3 text-xs">{message.status}</span>}
                    <time dateTime={message.at} className="text-ink-3 ml-auto text-xs whitespace-nowrap">
                        {dateTime(message.at)}
                    </time>
                </span>
                <span className="text-ink-3 truncate text-xs">Para: {recipients.join(', ')}</span>
            </summary>

            <div className="flex flex-col gap-3 px-5 pb-5">
                <MailFrame title={`Correo de ${message.from}`} html={message.html} text={message.text} />

                {inbound && message.attachments.length > 0 ? (
                    <ul aria-label="Adjuntos" className="flex flex-wrap gap-2">
                        {message.attachments.map((file) => (
                            <li key={file.id}>
                                {file.stored ? (
                                    <a href={`/plataforma/correos/adjuntos/${file.id}`} className="btn btn-sm" download>
                                        <Icon icon={cilCloudDownload} size={14} />
                                        {file.filename}
                                        <span className="text-ink-3 text-xs font-normal">{fileSize(file.size)}</span>
                                    </a>
                                ) : (
                                    <span className="btn btn-sm btn-disabled" title="Todavía no se pudo descargar de Mailtrap">
                                        <Icon icon={cilPaperclip} size={14} />
                                        {file.filename}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : null}

                {inbound ? (
                    <nav aria-label="Responder" className="flex flex-wrap gap-2">
                        {REPLY_ACTIONS.map((action) => (
                            <Link
                                key={action.mode}
                                href={`/plataforma/correos/redactar?responder=${message.id}&modo=${action.mode}`}
                                className={cn('btn btn-sm', action.mode === 'responder' && 'btn-primary')}
                            >
                                <Icon icon={action.icon} size={14} />
                                {action.label}
                            </Link>
                        ))}
                    </nav>
                ) : null}
            </div>
        </details>
    );
}
