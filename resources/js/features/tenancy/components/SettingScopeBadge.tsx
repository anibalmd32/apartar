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

export function SettingScopeBadge({ scope, note }: { scope: SettingScope; note: string }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <Badge tone={TONES[scope]} outline>
                {LABELS[scope]}
            </Badge>

            <span className="opacity-70">{note}</span>
        </span>
    );
}
