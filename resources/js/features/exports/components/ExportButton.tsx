import { Button } from '@/components/ui/button';
import { DisabledReason } from '@/components/ui/disabled-reason';
import { Icon } from '@/components/ui/icon';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { EXPORTS_URL, type ExportType } from '@/types/exports';
import { cilCloudDownload } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export function ExportButton({ type, filters }: { type: ExportType; filters: Readonly<Record<string, unknown>> }) {
    const plan = usePlanLimits();
    const [sending, setSending] = useState(false);
    const available = plan.hasFeature('excel_export');

    const applied = Object.fromEntries(
        Object.entries(filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== ''),
    );

    const button = (
        <Button
            variant="secondary"
            disabled={!available}
            loading={sending}
            onClick={() =>
                router.post(EXPORTS_URL, { type, filters: applied }, { onStart: () => setSending(true), onFinish: () => setSending(false) })
            }
        >
            <Icon icon={cilCloudDownload} />
            Exportar a Excel
        </Button>
    );

    if (available) {
        return button;
    }

    const upgrade = plan.featureUpgrade('excel_export');

    return (
        <DisabledReason
            reason={`${plan.featureLabel('excel_export')} no está incluida en tu plan${upgrade !== null ? `; la incluye ${upgrade.planName}` : ''}.`}
        >
            {button}
        </DisabledReason>
    );
}
