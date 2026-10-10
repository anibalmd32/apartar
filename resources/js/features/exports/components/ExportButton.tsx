import { Button } from '@/components/ui/button';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { EXPORTS_URL, type ExportType } from '@/types/exports';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * «Exportar a Excel» (`REQ-BOF-043` RB-1, RB-2, RB-3, `T-070`).
 *
 * Manda **los filtros que la pantalla aplicó** (`meta.filters`), no los de la
 * URL: lo que la tabla ignoró tampoco entra en el archivo. El servidor solo
 * encola y responde; la exportación aparece en la pantalla de exportaciones.
 *
 * Sin la función en el plan, el botón **se ve deshabilitado con el motivo** al
 * lado (RB-1, `REQ-SUB-004` RB-7): el negocio tiene que ver lo que se está
 * perdiendo. Si alguien lo fuerza, el servidor responde `403` igual.
 */
export function ExportButton({ type, filters }: { type: ExportType; filters: Readonly<Record<string, unknown>> }) {
    const plan = usePlanLimits();
    const [sending, setSending] = useState(false);
    const available = plan.hasFeature('excel_export');

    const applied = Object.fromEntries(
        Object.entries(filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== ''),
    );

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                variant="secondary"
                disabled={!available}
                loading={sending}
                onClick={() =>
                    router.post(EXPORTS_URL, { type, filters: applied }, { onStart: () => setSending(true), onFinish: () => setSending(false) })
                }
            >
                Exportar a Excel
            </Button>
            <UpgradePrompt feature="excel_export" />
        </div>
    );
}
