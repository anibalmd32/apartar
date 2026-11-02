import { TextField } from '@/components/ui/text-field';
import { PushPreview } from '@/features/notifications/components/PushPreview';
import { useId } from 'react';

export interface PushMessage {
    readonly title: string;
    readonly body: string;
    readonly url: string;
}

export function PushMessageFields({
    message,
    limits,
    businessName,
    onChange,
}: {
    message: PushMessage;
    limits: { readonly title: number; readonly body: number };
    businessName: string;
    onChange: (next: PushMessage) => void;
}) {
    const bodyId = useId();
    const { title, body, url } = message;

    return (
        <>
            <TextField
                label="Título"
                value={title}
                onChange={(event) => onChange({ ...message, title: event.target.value })}
                hint={`${title.length} / ${limits.title} caracteres`}
            />
            <fieldset className="fieldset w-full">
                <label className="fieldset-legend" htmlFor={bodyId}>
                    Texto
                </label>
                <textarea
                    id={bodyId}
                    className="textarea w-full"
                    rows={3}
                    value={body}
                    onChange={(event) => onChange({ ...message, body: event.target.value })}
                />
                <p className="label text-xs">
                    {body.length} / {limits.body} caracteres
                </p>
            </fieldset>
            <PushPreview businessName={businessName} title={title} body={body} className="my-2 lg:hidden" />
            <TextField
                label="Enlace (opcional)"
                type="url"
                value={url}
                onChange={(event) => onChange({ ...message, url: event.target.value })}
                hint={
                    url.trim() !== '' && !url.includes(window.location.host)
                        ? 'Ese enlace se abrirá fuera de la plataforma.'
                        : 'Lo que se abre al tocar el aviso.'
                }
            />
        </>
    );
}
