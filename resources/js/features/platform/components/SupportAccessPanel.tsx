import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { type SupportGrantRow } from '@/types/platform';
import { cilShieldAlt } from '@coreui/icons';
import { Link, router, useForm } from '@inertiajs/react';

interface SupportAccessPanelProps {
    slug: string;
    myGrant: { id: number; expiresAt: string } | null;
    grants: readonly SupportGrantRow[];
}

export function SupportAccessPanel({ slug, myGrant, grants }: SupportAccessPanelProps) {
    const form = useForm({ reason: '' });
    const time = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });
    const base = `/plataforma/organizaciones/${slug}`;

    return (
        <SectionCard title="Acceso de soporte" aria-label="Acceso de soporte">
            {myGrant !== null ? (
                <div className="flex flex-col gap-3 text-sm">
                    <p className="text-ink-2">Tienes acceso de lectura hasta el {time.format(new Date(myGrant.expiresAt))}.</p>
                    <div className="flex flex-wrap gap-2">
                        <Link href={`${base}/datos/apartados`} className="btn btn-sm">
                            Ver apartados
                        </Link>
                        <Link href={`${base}/datos/clientes`} className="btn btn-sm">
                            Ver clientes
                        </Link>
                        <Button variant="ghost" size="sm" onClick={() => router.delete(`${base}/soporte/${myGrant.id}`)}>
                            Cerrar acceso
                        </Button>
                    </div>
                </div>
            ) : (
                <form
                    className="flex flex-col gap-3 text-sm"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`${base}/soporte`, { onSuccess: () => form.reset() });
                    }}
                >
                    <p className="text-ink-2">
                        Para ver datos de sus clientes hace falta un acceso temporal de solo lectura. El negocio verá un aviso mientras esté abierto.
                    </p>
                    <label className="flex flex-col gap-1.5">
                        <span className="text-[13px] font-semibold">Motivo (el negocio lo verá)</span>
                        <textarea
                            className="textarea w-full"
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            minLength={20}
                            maxLength={500}
                        />
                    </label>
                    {form.errors.reason ? <p className="text-error">{form.errors.reason}</p> : null}
                    <Button type="submit" variant="secondary" block loading={form.processing}>
                        {form.processing ? null : <Icon icon={cilShieldAlt} />}
                        Abrir acceso de lectura
                    </Button>
                </form>
            )}

            {grants.length > 0 ? (
                <ul className="divide-line border-line flex flex-col divide-y border-t text-sm">
                    {grants.map((grant) => (
                        <li key={grant.id} className="flex flex-col gap-1 py-2.5">
                            <span className="flex flex-wrap items-center gap-2">
                                {grant.active ? <Badge tone="warning">Vigente</Badge> : <Badge>Cerrado</Badge>}
                                <span className="font-semibold">{grant.operator}</span>
                                <span className="text-ink-3 text-xs">{time.format(new Date(grant.grantedAt))}</span>
                            </span>
                            <span className="text-ink-2">{grant.reason}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </SectionCard>
    );
}
