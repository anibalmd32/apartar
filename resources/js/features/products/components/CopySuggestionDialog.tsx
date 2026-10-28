import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { remainingLabel } from '@/features/products/hooks/useCopywriter';
import { richTextContentClass } from '@/lib/rich-text';
import { cn } from '@/lib/utils';
import { type CopySuggestion } from '@/types/product';
import { useState } from 'react';

interface CopySuggestionDialogProps {
    suggestion: CopySuggestion;
    onUse: (description: string, characteristics: string[]) => void;
    onDiscard: () => void;
}

export function CopySuggestionDialog({ suggestion, onUse, onDiscard }: CopySuggestionDialogProps) {
    const [checked, setChecked] = useState<ReadonlySet<number>>(() => new Set(suggestion.characteristics.map((_, index) => index)));

    const toggle = (index: number) => {
        setChecked((current) => {
            const next = new Set(current);

            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }

            return next;
        });
    };

    const use = () =>
        onUse(
            suggestion.description,
            suggestion.characteristics.filter((_, index) => checked.has(index)),
        );

    return (
        <Modal
            open
            onClose={onDiscard}
            title="Sugerencia de la IA"
            description="Revísala antes de usarla: la IA puede equivocarse. Al usarla reemplaza la descripción actual."
            footnote={remainingLabel(suggestion.remaining)}
            actions={
                <>
                    <Button variant="ghost" onClick={onDiscard}>
                        Descartar
                    </Button>
                    <Button onClick={use}>Usar sugerencia</Button>
                </>
            }
        >
            <div
                data-testid="copy-suggestion"
                className={cn('border-line bg-surface-2 rounded-xl border px-4 py-3 text-sm', richTextContentClass)}
                dangerouslySetInnerHTML={{ __html: suggestion.description }}
            />

            {suggestion.characteristics.length > 0 ? (
                <fieldset className="flex flex-col gap-2">
                    <legend className="text-ink-2 mb-1 text-[13px] font-semibold">Características que se agregarán</legend>
                    {suggestion.characteristics.map((characteristic, index) => (
                        <label key={characteristic} className="flex cursor-pointer items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="checkbox checkbox-sm checkbox-primary"
                                checked={checked.has(index)}
                                onChange={() => toggle(index)}
                            />
                            {characteristic}
                        </label>
                    ))}
                </fieldset>
            ) : null}
        </Modal>
    );
}
