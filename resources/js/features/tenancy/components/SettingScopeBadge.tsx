import { Badge, type BadgeTone } from '@/components/ui/badge';
import { type SettingScope } from '@/types/backoffice';

/**
 * A qué alcanza cambiar un ajuste, junto al campo que se cambia
 * (`REQ-ORG-006` RB-4).
 *
 * RB-4 exige que **cada clave declare su alcance en la UI**, y el motivo es
 * concreto: guardar no es aplicar. Cambiar la ventana del abono inicial no
 * mueve los `expires_at` ya fijados, y quien lo cambia tiene que saberlo
 * **antes** de guardar —no después, cuando llame un cliente preguntando por
 * qué su apartado sigue venciendo mañana—.
 *
 * El texto lo manda el servidor (`SettingScope::description()`): aquí solo se
 * elige el color, porque una frase escrita en el `.tsx` acabaría diciendo una
 * cosa distinta de la que hace el cálculo.
 */

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
