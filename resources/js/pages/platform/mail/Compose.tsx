import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { RichTextField } from '@/components/ui/rich-text-field';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { dateTime } from '@/features/platform/mail/format';
import PlatformLayout from '@/layouts/platform-layout';
import { type ComposeSource } from '@/types/mail';
import { cilArrowLeft, cilPaperclip, cilSend } from '@coreui/icons';
import { Link, useForm } from '@inertiajs/react';

interface ComposeProps {
    mode: string;
    modeLabel: string;
    from: string;
    source: ComposeSource | null;
    defaults: { to: string; cc: string; subject: string };
}

export default function MailCompose({ mode, modeLabel, from, source, defaults }: ComposeProps) {
    const form = useForm({ mode, source: source?.id ?? null, to: defaults.to, cc: defaults.cc, subject: defaults.subject, body: '' });
    const back = source === null ? '/plataforma/correos/bandeja' : `/plataforma/correos/bandeja/${source.threadId}`;

    return (
        <PlatformLayout
            title={modeLabel}
            description={`Sale desde ${from}.`}
            back={
                <Link href={back} className="text-ink-2 hover:text-base-content inline-flex items-center gap-1.5 self-start text-[13px]">
                    <Icon icon={cilArrowLeft} size={14} />
                    {source === null ? 'Bandeja' : 'Conversación'}
                </Link>
            }
        >
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                <SectionCard aria-label="Correo">
                    <form
                        className="flex flex-col gap-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/plataforma/correos/enviar', { preserveScroll: true });
                        }}
                    >
                        <TextField
                            label="Para"
                            value={form.data.to}
                            onChange={(event) => form.setData('to', event.target.value)}
                            error={form.errors.to}
                            hint="Separa varias direcciones con comas."
                            autoComplete="off"
                            required
                        />
                        <TextField
                            label="Copia"
                            value={form.data.cc}
                            onChange={(event) => form.setData('cc', event.target.value)}
                            error={form.errors.cc}
                            autoComplete="off"
                        />
                        <TextField
                            label="Asunto"
                            value={form.data.subject}
                            onChange={(event) => form.setData('subject', event.target.value)}
                            error={form.errors.subject}
                            maxLength={255}
                            required
                        />
                        <RichTextField
                            label="Mensaje"
                            value={form.data.body}
                            onChange={(html) => form.setData('body', html)}
                            error={form.errors.body}
                        />
                        <div className="flex justify-end pt-2">
                            <Button type="submit" loading={form.processing} disabled={form.processing}>
                                {form.processing ? null : <Icon icon={cilSend} size={16} />}
                                Enviar
                            </Button>
                        </div>
                    </form>
                </SectionCard>

                {source === null ? null : (
                    <SectionCard title="Correo original" subtitle={`${source.from} · ${dateTime(source.receivedAt)}`} aria-label="Correo original">
                        <p className="text-sm font-semibold">{source.subject}</p>
                        <blockquote className="border-line text-ink-2 border-l-2 pl-3 text-[13px] break-words whitespace-pre-wrap">
                            {source.excerpt}
                        </blockquote>
                        <p className="text-ink-3 text-xs">Se cita al final de tu mensaje.</p>
                        {source.attachments.length > 0 ? (
                            <ul aria-label="Adjuntos que se reenvían" className="flex flex-col gap-1 text-[13px]">
                                {source.attachments.map((name) => (
                                    <li key={name} className="flex items-center gap-1.5">
                                        <Icon icon={cilPaperclip} size={14} />
                                        {name}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </SectionCard>
                )}
            </div>
        </PlatformLayout>
    );
}
