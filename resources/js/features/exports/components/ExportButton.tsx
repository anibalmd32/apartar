import { Button } from '@/components/ui/button';
import { DisabledReason } from '@/components/ui/disabled-reason';
import { Icon } from '@/components/ui/icon';
import { type PageAction } from '@/components/ui/page-actions';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { EXPORTS_URL, type ExportType } from '@/types/exports';
import { cilCloudDownload } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useState } from 'react';

type ExportFilters = Readonly<Record<string, unknown>>;

export function useExportAction(type: ExportType, filters: ExportFilters): PageAction {
    const plan = usePlanLimits();
    const [sending, setSending] = useState(false);
    const available = plan.hasFeature('excel_export');
    const upgrade = plan.featureUpgrade('excel_export');

    const applied = Object.fromEntries(
        Object.entries(filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== ''),
    );

    return {
        label: 'Exportar a Excel',
        icon: cilCloudDownload,
        variant: 'secondary',
        loading: sending,
        disabled: !available,
        mobile: available ? 'icon' : undefined,
        disabledReason: available
            ? undefined
            : `${plan.featureLabel('excel_export')} no está incluida en tu plan${upgrade !== null ? `; la incluye ${upgrade.planName}` : ''}.`,
        onClick: () => router.post(EXPORTS_URL, { type, filters: applied }, { onStart: () => setSending(true), onFinish: () => setSending(false) }),
    };
}

export function ExportButton({ type, filters }: { type: ExportType; filters: ExportFilters }) {
    const action = useExportAction(type, filters);

    const button = (
        <Button
            variant="secondary"
            aria-label={action.label}
            className="max-lg:btn-square max-lg:size-11"
            disabled={action.disabled}
            loading={action.loading}
            onClick={action.onClick}
        >
            {action.loading ? null : <Icon icon={cilCloudDownload} />}
            <span className="max-lg:sr-only">{action.label}</span>
        </Button>
    );

    return action.disabledReason === undefined ? button : <DisabledReason reason={action.disabledReason}>{button}</DisabledReason>;
}
