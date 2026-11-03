import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useState } from 'react';

type Detail = 'layaways' | 'installments';

const DETAILS: readonly { value: Detail; label: string }[] = [
    { value: 'layaways', label: 'Por apartado' },
    { value: 'installments', label: 'Por cuota' },
];

export function GroupingSwitch({ url, grouped, detail = 'layaways' }: { url: string; grouped: boolean; detail?: Detail }) {
    const [saving, setSaving] = useState(false);

    const save = (patch: { grouped?: boolean; detail?: Detail }) => {
        setSaving(true);
        router.put(url, patch, { preserveScroll: true, preserveState: false, onFinish: () => setSaving(false) });
    };

    return (
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
            <label className="text-ink-2 flex min-h-11 cursor-pointer items-center gap-2.5 text-sm">
                <input
                    type="checkbox"
                    className="toggle toggle-primary toggle-sm"
                    checked={grouped}
                    disabled={saving}
                    onChange={(event) => save({ grouped: event.target.checked })}
                />
                Agrupar por cliente
            </label>
            {grouped ? (
                <div role="group" aria-label="Ver dentro de cada cliente" className="bg-base-200 inline-flex rounded-full p-1">
                    {DETAILS.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={detail === option.value}
                            disabled={saving}
                            onClick={() => (detail === option.value ? undefined : save({ detail: option.value }))}
                            className={cn(
                                'min-h-9 rounded-full px-3.5 text-[13px] font-semibold max-lg:min-h-11',
                                detail === option.value ? 'bg-base-100 text-base-content shadow-sm' : 'text-ink-2',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
