import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { PushHistory, type PushHistoryEntry } from '@/features/notifications/components/PushHistory';
import { PushPreview } from '@/features/notifications/components/PushPreview';
import { PushReach, type PushReport } from '@/features/notifications/components/PushReach';
import { RecipientPicker, type RecipientOption } from '@/features/notifications/components/RecipientPicker';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { JsonRequestError, postJson } from '@/lib/json';
import { type BackofficeSharedData } from '@/types/backoffice';
import { cilPeople } from '@coreui/icons';
import { usePage } from '@inertiajs/react';
import { useId, useState } from 'react';

const SEGMENTS = [
    { value: 'due_today', label: 'Vencen hoy' },
    { value: 'due_within_7_days', label: 'Vencen en 7 días' },
    { value: 'overdue_over_15_days', label: 'Mora de más de 15 días' },
    { value: 'completed_not_delivered', label: 'Pagados sin entregar' },
] as const;

interface ComposeProps {
    readonly available: boolean;
    readonly limits: { readonly title: number; readonly body: number };
    readonly previewUrl: string;
    readonly sendUrl: string;
    readonly recipientsUrl: string;
    readonly preselected: RecipientOption | null;
    readonly hourlyLimit: number;
    readonly history: readonly PushHistoryEntry[];
}

type Mode = 'segment' | 'selection';

const MODES = [
    { value: 'segment', label: 'Segmento de cobranza' },
    { value: 'selection', label: 'Selección manual' },
] as const;

export default function Compose({ available, limits, previewUrl, sendUrl, recipientsUrl, preselected, hourlyLimit, history }: ComposeProps) {
    const { teamMember } = usePage<BackofficeSharedData>().props;
    const [mode, setMode] = useState<Mode>(preselected !== null ? 'selection' : 'segment');
    const [chosen, setChosen] = useState<RecipientOption[]>(preselected !== null ? [preselected] : []);
    const [segment, setSegment] = useState<string>(SEGMENTS[0].value);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [url, setUrl] = useState('');
    const [preview, setPreview] = useState<PushReport | null>(null);
    const [result, setResult] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const bodyId = useId();

    const audience = mode === 'selection' ? { customers: chosen.map((option) => option.id) } : { segment: { preset: segment } };
    const payload = () => ({ title, body, url: url.trim() === '' ? null : url.trim(), ...audience });
    const valid =
        title.trim() !== '' &&
        body.trim() !== '' &&
        title.length <= limits.title &&
        body.length <= limits.body &&
        (mode === 'segment' || chosen.length > 0);

    const run = async (action: () => Promise<void>) => {
        setBusy(true);
        setError(null);

        try {
            await action();
        } catch (failure) {
            setError(failure instanceof JsonRequestError ? failure.message : 'No se pudo completar. Inténtalo de nuevo.');
        } finally {
            setBusy(false);
        }
    };

    const send = () =>
        void run(async () => {
            const response = await postJson<{ report: PushReport }>(sendUrl, payload());
            setResult(`Enviado a ${response.report.queued} clientes.`);
            setPreview(null);
        });

    return (
        <BackofficeLayout
            title="Notificaciones"
            description="Manda un aviso al teléfono de tus clientes. Les llega aunque no tengan la página abierta."
        >
            {!available ? <UpgradePrompt feature="web_push" /> : null}
            {result !== null ? <Alert tone="success">{result}</Alert> : null}
            {error !== null ? <Alert tone="error">{error}</Alert> : null}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_400px] lg:items-start lg:gap-6">
                <SectionCard title="Nuevo aviso">
                    <fieldset className="flex flex-col gap-1" disabled={!available || busy}>
                        <FilterTabs
                            label="Destinatarios"
                            tabs={MODES}
                            value={mode}
                            onChange={(value) => {
                                setMode(value);
                                setPreview(null);
                            }}
                            className="mb-2"
                        />
                        {mode === 'segment' ? (
                            <SelectField
                                label="A quién"
                                value={segment}
                                onChange={(event) => {
                                    setSegment(event.target.value);
                                    setPreview(null);
                                }}
                                options={SEGMENTS}
                                hint="Un grupo de tu cobranza."
                            />
                        ) : (
                            <RecipientPicker
                                searchUrl={recipientsUrl}
                                selected={chosen}
                                onChange={(next) => {
                                    setChosen(next);
                                    setPreview(null);
                                }}
                            />
                        )}
                        <TextField
                            label="Título"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
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
                                onChange={(event) => setBody(event.target.value)}
                            />
                            <p className="label text-xs">
                                {body.length} / {limits.body} caracteres
                            </p>
                        </fieldset>
                        <TextField
                            label="Enlace (opcional)"
                            type="url"
                            value={url}
                            onChange={(event) => setUrl(event.target.value)}
                            hint={
                                url.trim() !== '' && !url.includes(window.location.host)
                                    ? 'Ese enlace se abrirá fuera de la plataforma.'
                                    : 'Lo que se abre al tocar el aviso.'
                            }
                        />

                        <p className="text-ink-3 mt-2 text-xs">Puedes enviar hasta {hourlyLimit} avisos por hora.</p>

                        <div className="mt-3">
                            {preview === null ? (
                                <Button
                                    disabled={!valid}
                                    loading={busy}
                                    className="w-full sm:w-auto"
                                    onClick={() => void run(async () => setPreview(await postJson<PushReport>(previewUrl, payload())))}
                                >
                                    <Icon icon={cilPeople} />
                                    Ver a cuántos llega
                                </Button>
                            ) : (
                                <PushReach report={preview} busy={busy} onConfirm={send} onChange={() => setPreview(null)} />
                            )}
                        </div>
                    </fieldset>
                </SectionCard>

                <div className="flex flex-col gap-4">
                    <PushPreview businessName={teamMember?.organization?.name ?? 'Tu negocio'} title={title} body={body} />
                    <PushHistory history={history} />
                </div>
            </div>
        </BackofficeLayout>
    );
}
