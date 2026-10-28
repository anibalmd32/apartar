import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { remainingLabel } from '@/features/products/hooks/useCopywriter';
import { richTextContentClass } from '@/lib/rich-text';
import { cn } from '@/lib/utils';
import { type CopySuggestion } from '@/types/product';
import { useState } from 'react';

interface CopySuggestionDialogProps {
    suggestion: CopySuggestion;
    onUse: (description: string, characteristics: string[], tags: string[]) => void;
    onDiscard: () => void;
}

export function CopySuggestionDialog({ suggestion, onUse, onDiscard }: CopySuggestionDialogProps) {
    const tags = suggestion.tags ?? [];
    const [checked, setChecked] = useState<ReadonlySet<number>>(() => new Set(suggestion.characteristics.map((_, index) => index)));
    const [checkedTags, setCheckedTags] = useState<ReadonlySet<string>>(() => new Set(tags));

    const toggleTag = (tag: string) =>
        setCheckedTags((current) => {
            const next = new Set(current);

            if (next.has(tag)) {
                next.delete(tag);
            } else {
                next.add(tag);
            }

            return next;
        });

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
            tags.filter((tag) => checkedTags.has(tag)),
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

            {tags.length > 0 ? (
                <fieldset className="flex flex-col gap-2">
                    <legend className="text-ink-2 mb-1 text-[13px] font-semibold">Etiquetas que se agregarán</legend>
                    <div className="flex flex-wrap gap-2">
                        {tags.map((tag) => (
                            <label key={tag} className="border-line flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm">
                                <input
                                    type="checkbox"
                                    className="checkbox checkbox-xs checkbox-primary"
                                    checked={checkedTags.has(tag)}
                                    onChange={() => toggleTag(tag)}
                                />
                                {tag}
                            </label>
                        ))}
                    </div>
                </fieldset>
            ) : null}
        </Modal>
    );
}
