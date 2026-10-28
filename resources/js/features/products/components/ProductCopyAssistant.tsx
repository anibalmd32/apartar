import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DisabledReason } from '@/components/ui/disabled-reason';
import { Icon } from '@/components/ui/icon';
import { CopySuggestionDialog } from '@/features/products/components/CopySuggestionDialog';
import { type CopywriterInput, remainingLabel, useCopywriter } from '@/features/products/hooks/useCopywriter';
import { type AiAvailability, type CopywritingMode } from '@/types/product';
import { cilLightbulb, cilPencil } from '@coreui/icons';
import { type ReactElement } from 'react';

interface ProductCopyAssistantProps {
    ai: AiAvailability;
    input: CopywriterInput;
    hasStoredPhotos: boolean;
    onApply: (description: string, characteristics: string[], tags: string[]) => void;
}

function hasText(html: string): boolean {
    return (
        html
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;/g, ' ')
            .trim() !== ''
    );
}

function withReason(button: ReactElement, reason: string | null) {
    return reason === null ? button : <DisabledReason reason={reason}>{button}</DisabledReason>;
}

export function ProductCopyAssistant({ ai, input, hasStoredPhotos, onApply }: ProductCopyAssistantProps) {
    const copywriter = useCopywriter(ai.remaining);

    const exhausted = copywriter.remaining !== null && copywriter.remaining <= 0;
    const blocked = !ai.available ? 'La IA todavía no está configurada.' : exhausted ? 'Ya usaste las sugerencias de hoy. Vuelve mañana.' : null;
    const canSuggest = input.name.trim() !== '' || input.photos.length > 0 || hasStoredPhotos;
    const canImprove = hasText(input.description);

    const ask = (mode: CopywritingMode) => void copywriter.request(mode, input);

    const button = (mode: CopywritingMode, label: string, icon: string[], enabled: boolean) => (
        <Button
            size="sm"
            variant="soft"
            onClick={() => ask(mode)}
            disabled={blocked !== null || !enabled || copywriter.pending !== null}
            loading={copywriter.pending === mode}
        >
            <Icon icon={icon} />
            {label}
        </Button>
    );

    return (
        <div className="mt-3 flex flex-col gap-2 sm:col-span-2">
            <div className="flex flex-wrap items-center gap-3">
                {withReason(
                    button('suggest', 'Sugerir con IA', cilLightbulb, canSuggest),
                    blocked ?? (canSuggest ? null : 'Escribe el nombre o agrega una foto para que la IA tenga de dónde partir.'),
                )}
                {withReason(
                    button('improve', 'Mejorar con IA', cilPencil, canImprove),
                    blocked ?? (canImprove ? null : 'Escribe primero una descripción para que la IA la mejore.'),
                )}
                {copywriter.pending !== null ? (
                    <span role="status" className="text-ink-3 text-xs">
                        La IA está escribiendo…
                    </span>
                ) : ai.available && copywriter.remaining !== null ? (
                    <span className="text-ink-3 text-xs">{remainingLabel(copywriter.remaining)}</span>
                ) : null}
            </div>

            {copywriter.error ? <Alert tone="error">{copywriter.error}</Alert> : null}

            {copywriter.suggestion ? (
                <CopySuggestionDialog
                    suggestion={copywriter.suggestion}
                    onDiscard={copywriter.dismiss}
                    onUse={(description, characteristics, tags) => {
                        onApply(description, characteristics, tags);
                        copywriter.dismiss();
                    }}
                />
            ) : null}
        </div>
    );
}
