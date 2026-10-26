import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { type PlatformSettingRow } from '@/types/platform';
import { useForm } from '@inertiajs/react';

export function PlatformSettingsForm({ settings }: { settings: readonly PlatformSettingRow[] }) {
    const form = useForm<{ settings: Record<string, string> }>({
        settings: Object.fromEntries(settings.map((setting) => [setting.key, String(setting.value)])),
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <SectionCard title="Parámetros" subtitle="Valores globales. Un valor fuera de rango se lee como el valor por defecto.">
            <form
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put('/plataforma/ajustes', { preserveScroll: true });
                }}
            >
                <div className="grid gap-x-5 sm:grid-cols-2">
                    {settings.map((setting) => (
                        <TextField
                            key={setting.key}
                            label={setting.label}
                            type="number"
                            inputMode="numeric"
                            min={setting.min}
                            max={setting.max}
                            step={1}
                            required
                            value={form.data.settings[setting.key] ?? ''}
                            onChange={(event) => form.setData('settings', { ...form.data.settings, [setting.key]: event.target.value })}
                            error={errors[`settings.${setting.key}`]}
                            hint={`En ${setting.unit}, de ${setting.min} a ${setting.max}. Por defecto, ${setting.default}.`}
                        />
                    ))}
                </div>
                {errors.settings ? <p className="text-error text-sm">{errors.settings}</p> : null}
                <Button type="submit" className="self-start" loading={form.processing} disabled={!form.isDirty}>
                    Guardar ajustes
                </Button>
            </form>
        </SectionCard>
    );
}
