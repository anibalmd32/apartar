import { BackLink } from '@/components/ui/back-link';
import { Button } from '@/components/ui/button';
import { FormActionBar } from '@/components/ui/form-action-bar';
import { Icon } from '@/components/ui/icon';
import { RichTextField } from '@/components/ui/rich-text-field';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { composeBack } from '@/features/platform/mail/composeBack';
import { dateTime } from '@/features/platform/mail/format';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import PlatformLayout from '@/layouts/platform-layout';
import { pf } from '@/lib/surfaces';
import { cn } from '@/lib/utils';
import { type ComposeSource } from '@/types/mail';
import { cilPaperclip, cilSend } from '@coreui/icons';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const ADDRESS_INPUT = { inputMode: 'email', autoCapitalize: 'off', autoCorrect: 'off', spellCheck: false, autoComplete: 'off' } as const;

interface ComposeProps {
    mode: string;
    modeLabel: string;
    from: string;
    source: ComposeSource | null;
    defaults: { to: string; cc: string; subject: string };
}

export default function MailCompose({ mode, modeLabel, from, source, defaults }: ComposeProps) {
    const form = useForm({ mode, source: source?.id ?? null, to: defaults.to, cc: defaults.cc, subject: defaults.subject, body: '' });
    const leaveGuard = useLeaveGuard(form.isDirty && !form.processing, {
        title: '¿Salir sin enviar?',
        description: 'Tienes un correo a medio escribir. Si sales ahora, se pierde.',
    });
    const back = composeBack(usePage().url, source?.threadId ?? null);
    const [withCopy, setWithCopy] = useState(defaults.cc !== '');

    return (
        <PlatformLayout title={modeLabel} description={`Sale desde ${from}.`} back={<BackLink href={back.href}>{back.label}</BackLink>}>
            <div className={cn('grid grid-cols-1 gap-4', source !== null && 'lg:grid-cols-[minmax(0,1fr)_360px]')}>
                <SectionCard aria-label="Correo">
                    <form
                        className="flex flex-col gap-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(pf('/correos/enviar'), { preserveScroll: true });
                        }}
                    >
                        <TextField
                            label="Para"
                            value={form.data.to}
                            onChange={(event) => form.setData('to', event.target.value)}
                            error={form.errors.to}
                            hint="Separa varias direcciones con comas."
                            {...ADDRESS_INPUT}
                            required
                        />
                        {withCopy || form.errors.cc ? (
                            <TextField
                                label="Copia"
                                value={form.data.cc}
                                onChange={(event) => form.setData('cc', event.target.value)}
                                error={form.errors.cc}
                                {...ADDRESS_INPUT}
                            />
                        ) : (
                            <button type="button" className="link link-primary touch:min-h-11 self-start text-sm" onClick={() => setWithCopy(true)}>
                                Añadir copia
                            </button>
                        )}
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
                        <FormActionBar sticky className="mt-2 lg:justify-end">
                            <Button type="submit" loading={form.processing} disabled={form.processing} className="max-lg:min-h-11">
                                {form.processing ? null : <Icon icon={cilSend} size={16} />}
                                Enviar
                            </Button>
                        </FormActionBar>
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
            {leaveGuard}
        </PlatformLayout>
    );
}
