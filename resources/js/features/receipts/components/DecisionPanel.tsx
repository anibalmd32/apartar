import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { ApprovalForm } from '@/features/receipts/components/ApprovalForm';
import { RejectForm } from '@/features/receipts/components/RejectForm';
import { cn } from '@/lib/utils';
import { type ReceiptReviewProps } from '@/types/receipt';
import { cilCheckAlt, cilX } from '@coreui/icons';
import { useState } from 'react';

type Mode = 'approve' | 'reject';

export function DecisionStatus({ receipt, layaway, approval }: Pick<ReceiptReviewProps, 'receipt' | 'layaway' | 'approval'>) {
    if (approval !== null) {
        return null;
    }

    if (receipt.status === 'pending_review') {
        return (
            <Alert tone="warning" title="No se puede aprobar">
                El apartado está «{layaway.statusLabel}» y ya no admite pagos. Si hace falta, recházalo para avisarle al cliente.
            </Alert>
        );
    }

    return (
        <Alert tone={receipt.status === 'approved' ? 'success' : 'info'} title="Este comprobante ya fue revisado">
            {receipt.reviewedBy !== null ? `${receipt.statusLabel} por ${receipt.reviewedBy}.` : `${receipt.statusLabel}.`}
            {receipt.rejectionReason !== null ? ` Motivo: ${receipt.rejectionReason}` : ''}
        </Alert>
    );
}

function DecisionForm({ mode, ...props }: ReceiptReviewProps & { mode: Mode }) {
    if (mode === 'approve' && props.approval !== null) {
        return (
            <ApprovalForm
                approval={props.approval}
                receipt={props.receipt}
                remaining={props.layaway.remaining}
                rate={props.rate}
                initialPreview={props.preview}
                initialError={props.previewError}
            />
        );
    }

    return props.rejection !== null ? <RejectForm rejection={props.rejection} /> : null;
}

export function DecisionPanel(props: ReceiptReviewProps) {
    const [mode, setMode] = useState<Mode>(props.approval !== null ? 'approve' : 'reject');

    if (props.approval === null && props.rejection === null) {
        return null;
    }

    const tab = (value: Mode, label: string, enabled: boolean) => (
        <button
            type="button"
            aria-pressed={mode === value}
            disabled={!enabled}
            onClick={() => setMode(value)}
            className={cn(
                'touch:h-11 h-[34px] flex-1 rounded-lg text-sm transition-colors disabled:opacity-40',
                mode === value ? 'bg-base-100 dark:bg-base-300 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
            )}
        >
            {label}
        </button>
    );

    return (
        <section
            aria-label="Decisión"
            className="bg-base-100 border-primary/50 shadow-card flex flex-col gap-3.5 rounded-2xl border-[1.5px] p-5 max-lg:hidden"
        >
            <div role="group" aria-label="Qué hacer con el comprobante" className="bg-surface-2 flex gap-0.5 rounded-[10px] p-[3px]">
                {tab('approve', 'Aprobar', props.approval !== null)}
                {tab('reject', 'Rechazar', props.rejection !== null)}
            </div>
            <DecisionForm mode={mode} {...props} />
        </section>
    );
}

export function DecisionBar(props: ReceiptReviewProps) {
    const [sheet, setSheet] = useState<Mode | null>(null);

    if (props.approval === null && props.rejection === null) {
        return null;
    }

    return (
        <>
            <div
                data-form-action-bar
                data-sticky
                role="group"
                aria-label="Decidir sobre el comprobante"
                className="bg-base-100 border-line sticky bottom-[calc(var(--overlay-bottom)+var(--consent-height))] z-30 -mx-4 mt-4 flex gap-3 border-t px-4 py-3 lg:hidden"
            >
                {props.rejection !== null ? (
                    <button type="button" className="btn h-12 flex-1" onClick={() => setSheet('reject')}>
                        <Icon icon={cilX} />
                        Rechazar
                    </button>
                ) : null}
                {props.approval !== null ? (
                    <button type="button" className="btn btn-primary h-12 flex-[1.4]" onClick={() => setSheet('approve')}>
                        <Icon icon={cilCheckAlt} />
                        Aprobar
                    </button>
                ) : null}
            </div>

            <Modal
                open={sheet !== null}
                onClose={() => setSheet(null)}
                title={sheet === 'reject' ? 'Rechazar el comprobante' : 'Aprobar el comprobante'}
                description={`Declaró ${props.receipt.declared} · Resta ${props.layaway.remaining.usd}`}
                size="md"
            >
                {sheet !== null ? <DecisionForm mode={sheet} {...props} /> : null}
            </Modal>
        </>
    );
}
