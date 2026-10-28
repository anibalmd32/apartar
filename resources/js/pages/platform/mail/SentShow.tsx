import { Badge } from '@/components/ui/badge';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { EventTimeline } from '@/features/platform/mail/EventTimeline';
import { count, dateTime, statusTone } from '@/features/platform/mail/format';
import { MailFrame } from '@/features/platform/mail/MailFrame';
import PlatformLayout from '@/layouts/platform-layout';
import { type SentMailDetail } from '@/types/mail';
import { cilArrowLeft } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

type View = 'html' | 'text' | 'headers';

const VIEWS: readonly { value: View; label: string }[] = [
    { value: 'html', label: 'Vista' },
    { value: 'text', label: 'Texto' },
    { value: 'headers', label: 'Cabeceras' },
];

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[88px_minmax(0,1fr)] gap-2 text-[13px]">
            <dt className="text-ink-3">{label}</dt>
            <dd className="break-words">{children}</dd>
        </div>
    );
}

export default function SentMailShow({ mail }: { mail: SentMailDetail }) {
    const [view, setView] = useState<View>('html');

    return (
        <PlatformLayout
            title={mail.subject === '' ? '(sin asunto)' : mail.subject}
            back={
                <Link
                    href="/plataforma/correos/enviados"
                    className="text-ink-2 hover:text-base-content inline-flex items-center gap-1.5 self-start text-[13px]"
                >
                    <Icon icon={cilArrowLeft} size={14} />
                    Enviados
                </Link>
            }
            actions={<Badge tone={statusTone(mail.status)}>{mail.statusLabel}</Badge>}
        >
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div className="flex min-w-0 flex-col gap-4">
                    <SectionCard aria-label="Datos del correo">
                        <dl className="flex flex-col gap-1.5">
                            <Row label="De">{mail.from}</Row>
                            <Row label="Para">{mail.to.join(', ')}</Row>
                            {mail.cc.length > 0 ? <Row label="Copia">{mail.cc.join(', ')}</Row> : null}
                            {mail.bcc.length > 0 ? <Row label="Copia oculta">{mail.bcc.join(', ')}</Row> : null}
                            <Row label="Tipo">{mail.category}</Row>
                            <Row label="Creado">{dateTime(mail.createdAt)}</Row>
                            {mail.operator ? <Row label="Escrito por">{mail.operator}</Row> : null}
                            {mail.organization ? (
                                <Row label="Negocio">
                                    <Link
                                        href={`/plataforma/organizaciones/${mail.organization.slug}`}
                                        className="text-primary font-semibold hover:underline"
                                    >
                                        {mail.organization.name}
                                    </Link>
                                </Row>
                            ) : null}
                            {mail.threadId ? (
                                <Row label="Conversación">
                                    <Link
                                        href={`/plataforma/correos/bandeja/${mail.threadId}`}
                                        className="text-primary font-semibold hover:underline"
                                    >
                                        Ver en la bandeja
                                    </Link>
                                </Row>
                            ) : null}
                            <Row label="Actividad">
                                {count(mail.opens)} aperturas · {count(mail.clicks)} clics
                            </Row>
                        </dl>
                    </SectionCard>

                    <SectionCard aria-label="Contenido" className="gap-3">
                        <FilterTabs label="Formato" tabs={VIEWS} value={view} onChange={setView} />
                        {view === 'html' ? <MailFrame title="Contenido del correo" html={mail.html} text={mail.text} /> : null}
                        {view === 'text' ? (
                            <pre className="bg-surface-2 rounded-xl p-4 text-[13px] break-words whitespace-pre-wrap">
                                {mail.text ?? 'Sin versión en texto.'}
                            </pre>
                        ) : null}
                        {view === 'headers' ? (
                            <dl className="bg-surface-2 flex flex-col gap-1 rounded-xl p-4 font-mono text-xs">
                                {Object.entries(mail.headers).map(([name, value]) => (
                                    <div key={name} className="break-all">
                                        <dt className="inline font-semibold">{name}:</dt> <dd className="inline">{value}</dd>
                                    </div>
                                ))}
                                {mail.mailtrapMessageId ? (
                                    <div className="break-all">
                                        <dt className="inline font-semibold">Id en Mailtrap:</dt> <dd className="inline">{mail.mailtrapMessageId}</dd>
                                    </div>
                                ) : null}
                            </dl>
                        ) : null}
                    </SectionCard>
                </div>

                <EventTimeline createdAt={mail.createdAt} sentAt={mail.sentAt} events={mail.events} />
            </div>
        </PlatformLayout>
    );
}
