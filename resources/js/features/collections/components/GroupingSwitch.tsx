import { router } from '@inertiajs/react';
import { useState } from 'react';

export function GroupingSwitch({ url, grouped }: { url: string; grouped: boolean }) {
    const [saving, setSaving] = useState(false);

    const change = (next: boolean) => {
        setSaving(true);
        router.put(url, { grouped: next }, { preserveScroll: true, preserveState: false, onFinish: () => setSaving(false) });
    };

    return (
        <label className="text-ink-2 flex min-h-11 cursor-pointer items-center gap-2.5 self-start text-sm">
            <input
                type="checkbox"
                className="toggle toggle-primary toggle-sm"
                checked={grouped}
                disabled={saving}
                onChange={(event) => change(event.target.checked)}
            />
            Agrupar por cliente
        </label>
    );
}
