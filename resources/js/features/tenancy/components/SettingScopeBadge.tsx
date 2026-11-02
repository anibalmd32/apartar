import { Badge, type BadgeTone } from '@/components/ui/badge';
import { type SettingScope } from '@/types/backoffice';

const TONES: Record<SettingScope, BadgeTone> = {
    immediate: 'success',
    daily: 'info',
    future_only: 'warning',
};

const LABELS: Record<SettingScope, string> = {
    immediate: 'Desde ya',
    daily: 'Mañana',
    future_only: 'Solo lo nuevo',
};

const MEANINGS: Record<SettingScope, string> = {
    immediate: 'Se aplica en la siguiente operación.',
    daily: 'Se recalcula mañana sobre los apartados en curso.',
    future_only: 'Solo vale para los apartados que crees después; no mueve fechas ya fijadas.',
};

export function SettingScopeBadge({ scope, hint }: { scope: SettingScope; hint?: string }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <Badge tone={TONES[scope]} outline>
                {LABELS[scope]}
            </Badge>
            {hint ? <span className="opacity-70">{hint}</span> : null}
        </span>
    );
}

export function SettingScopeLegend() {
    return (
        <ul
            aria-label="Cuándo se aplica cada cambio"
            className="bg-surface-2 text-ink-2 mb-2 flex flex-col gap-1.5 rounded-xl px-3.5 py-3 text-[13px]"
        >
            {(Object.keys(LABELS) as SettingScope[]).map((scope) => (
                <li key={scope} className="grid grid-cols-[7.5rem_1fr] items-start gap-2">
                    <Badge tone={TONES[scope]} outline className="justify-self-start">
                        {LABELS[scope]}
                    </Badge>
                    <span>{MEANINGS[scope]}</span>
                </li>
            ))}
        </ul>
    );
}
