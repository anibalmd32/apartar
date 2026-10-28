import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { type RowSelection } from '@/features/data-table/hooks/useRowSelection';
import { cilX } from '@coreui/icons';
import { type ReactNode, useEffect, useRef } from 'react';

interface SelectCheckboxProps {
    checked: boolean;
    indeterminate?: boolean;
    disabled?: boolean;
    label: string;
    title?: string;
    onChange: () => void;
}

export function SelectCheckbox({ checked, indeterminate = false, disabled = false, label, title, onChange }: SelectCheckboxProps) {
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ref.current) {
            ref.current.indeterminate = indeterminate;
        }
    }, [indeterminate]);

    return (
        <input
            ref={ref}
            type="checkbox"
            className="checkbox checkbox-sm checkbox-primary disabled:opacity-30"
            checked={checked}
            disabled={disabled}
            aria-label={label}
            title={title}
            onChange={onChange}
        />
    );
}

export function RowCheckbox<TRow>({ selection, row }: { selection: RowSelection<TRow>; row: TRow }) {
    const enabled = selection.canSelect(row);

    return (
        <SelectCheckbox
            checked={selection.isSelected(row)}
            disabled={!enabled}
            label={
                enabled
                    ? `Seleccionar ${selection.labelOf(row)}`
                    : `${selection.labelOf(row)}: ${selection.disabledHint ?? 'no se puede seleccionar'}`
            }
            title={enabled ? undefined : selection.disabledHint}
            onChange={() => selection.toggle(row)}
        />
    );
}

export function PageCheckbox<TRow>({ selection, children }: { selection: RowSelection<TRow>; children?: ReactNode }) {
    return (
        <label className="inline-flex cursor-pointer items-center gap-2">
            <SelectCheckbox
                checked={selection.allSelected}
                indeterminate={selection.someSelected}
                disabled={selection.selectableCount === 0}
                label="Seleccionar todos los de esta página"
                title={selection.selectableCount === 0 ? selection.disabledHint : undefined}
                onChange={selection.toggleAll}
            />
            {children}
        </label>
    );
}

export function SelectionBar<TRow>({ selection, actions }: { selection: RowSelection<TRow>; actions?: ReactNode }) {
    if (selection.count === 0) {
        return null;
    }

    return (
        <div
            role="region"
            aria-label="Acciones con la selección"
            className="bg-base-100 border-line shadow-pop sticky bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-30 flex flex-wrap items-center gap-2 rounded-2xl border px-3 py-2.5 sm:gap-3 sm:px-4 lg:bottom-3"
        >
            <span className="text-sm font-semibold" aria-live="polite">
                {selection.count === 1 ? '1 seleccionado' : `${selection.count} seleccionados`}
            </span>
            <div className="ml-auto flex flex-wrap items-center gap-2">
                {actions}
                <Button size="sm" variant="ghost" onClick={selection.clear}>
                    <Icon icon={cilX} />
                    Quitar selección
                </Button>
            </div>
        </div>
    );
}
