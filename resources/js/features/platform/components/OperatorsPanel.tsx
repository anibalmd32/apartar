import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { type PlatformOperatorRow } from '@/types/platform';
import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type PendingAction = { kind: 'disable' | 'enable' | 'password'; operator: PlatformOperatorRow };

const DIALOGS = {
    disable: {
        title: '¿Desactivar este operador?',
        label: 'Desactivar',
        description: 'Pierde la sesión en su siguiente petición y no podrá entrar.',
    },
    enable: { title: '¿Reactivar este operador?', label: 'Reactivar', description: 'Podrá volver a entrar con su contraseña actual.' },
    password: {
        title: '¿Restablecer su contraseña?',
        label: 'Restablecer',
        description: 'Se genera una contraseña temporal que tendrá que cambiar al entrar.',
    },
} as const;

export function OperatorsPanel({ operators }: { operators: readonly PlatformOperatorRow[] }) {
    const form = useForm({ name: '', email: '' });
    const [pending, setPending] = useState<PendingAction | null>(null);
    const operatorError = (usePage().props.errors as Record<string, string | undefined>).operator;
    const dialog = pending ? DIALOGS[pending.kind] : null;

    return (
        <SectionCard title="Operadores" subtitle="Personas con acceso a este panel. Todas tienen los mismos permisos.">
            <ul className="divide-line flex flex-col divide-y">
                {operators.map((operator) => (
                    <li key={operator.id} className="flex flex-wrap items-center gap-3 py-3">
                        <Avatar name={operator.name} size={34} />
                        <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {operator.name}
                                {operator.isMe ? <Badge tone="info">Tú</Badge> : null}
                                {!operator.active ? <Badge>Desactivado</Badge> : null}
                                {operator.active && operator.mustChangePassword ? <Badge tone="warning">Contraseña temporal</Badge> : null}
                            </span>
                            <span className="text-ink-3 block truncate text-xs">{operator.email}</span>
                        </span>
                        {operator.isMe ? null : (
                            <span className="flex gap-2">
                                {operator.active ? (
                                    <Button variant="ghost" size="sm" onClick={() => setPending({ kind: 'password', operator })}>
                                        Restablecer contraseña
                                    </Button>
                                ) : null}
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setPending({ kind: operator.active ? 'disable' : 'enable', operator })}
                                >
                                    {operator.active ? 'Desactivar' : 'Reactivar'}
                                </Button>
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {operatorError ? <p className="text-error text-sm">{operatorError}</p> : null}

            <form
                className="border-line flex flex-col gap-1 border-t pt-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/plataforma/operadores', { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <h3 className="text-[13px] font-semibold">Añadir operador</h3>
                <div className="grid gap-x-4 sm:grid-cols-2">
                    <TextField
                        label="Nombre"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                        required
                    />
                    <TextField
                        label="Correo"
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                        required
                    />
                </div>
                <Button type="submit" variant="secondary" size="sm" className="self-start" loading={form.processing}>
                    Crear con contraseña temporal
                </Button>
            </form>

            <ConfirmDialog
                open={pending !== null}
                onClose={() => setPending(null)}
                title={dialog?.title ?? ''}
                description={pending && dialog ? `${pending.operator.name}: ${dialog.description}` : undefined}
                confirmLabel={dialog?.label ?? ''}
                tone={pending?.kind === 'enable' ? 'primary' : 'danger'}
                method={pending?.kind === 'password' ? 'post' : 'patch'}
                url={pending ? `/plataforma/operadores/${pending.operator.id}/${pending.kind === 'password' ? 'contrasena' : 'acceso'}` : ''}
                data={pending && pending.kind !== 'password' ? { active: pending.kind === 'enable' } : undefined}
                options={{ preserveScroll: true }}
            />
        </SectionCard>
    );
}
