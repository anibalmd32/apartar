import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type TeamMember } from '@/types/backoffice';
import { type UsageState } from '@/types/plan';
import { router, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Equipo: quién entra al negocio (`REQ-ORG-004`, `REQ-ORG-005`).
 *
 * Es la pantalla que convierte el producto en algo que usa un negocio y no una
 * sola persona, y tiene una responsabilidad que no es evidente: **decir la
 * verdad sobre lo que no se puede hacer, antes de que lo intenten**.
 *
 * De ahí que ningún botón imposible esté simplemente ausente. El de invitar se
 * deshabilita con el cupo agotado y el motivo al lado; el de desactivar al
 * último `owner` y el de desactivarse uno mismo, igual. Un control que
 * desaparece deja a la persona buscándolo; uno deshabilitado que explica por
 * qué la deja sabiendo qué hacer. Y ninguno de los tres es el control de
 * acceso: eso es la policy del servidor (`REQ-ORG-003` RB-4).
 *
 * Los desactivados **siguen en la lista**, marcados. Esconderlos convertiría
 * la baja lógica en un borrado a efectos prácticos y dejaría al dueño sin
 * poder reactivar a nadie (`REQ-ORG-005` RB-7).
 */

interface TeamPageProps {
    members: TeamMember[];
    usage: UsageState;
}

interface InviteForm {
    [key: string]: string;
    name: string;
    email: string;
    role: string;
}

const EMPTY_INVITE: InviteForm = { name: '', email: '', role: 'member' };

const ROLE_OPTIONS = [
    { value: 'member', label: 'Empleado · opera el día a día' },
    { value: 'owner', label: 'Dueño · también gobierna el negocio' },
];

export default function TeamIndex({ members, usage }: TeamPageProps) {
    const { data, setData, post, processing, errors, reset } = useForm<InviteForm>({ ...EMPTY_INVITE });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/equipo', {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <BackofficeLayout
            title="Equipo"
            description="Quién entra a este backoffice y con qué permisos."
        >
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Invitar a alguien</h2>
                        <p className="text-sm opacity-70">{formatUsage(usage.used, usage.limit)} usuarios</p>
                    </header>

                    {usage.isAtLimit ? (
                        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                            <span>
                                Tu plan no admite más usuarios. Las invitaciones pendientes también ocupan sitio:
                                puedes revocar alguna o cambiar de plan.
                            </span>

                            <a className="btn btn-sm btn-warning" href="/backoffice/suscripcion">
                                Ver planes
                            </a>
                        </Alert>
                    ) : null}

                    <form className="flex flex-col gap-2 sm:flex-row sm:items-end" onSubmit={submit} noValidate>
                        <TextField
                            label="Nombre"
                            name="name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            error={errors.name}
                            autoComplete="off"
                            required
                        />

                        <TextField
                            label="Correo"
                            name="email"
                            type="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            error={errors.email}
                            autoComplete="off"
                            required
                        />

                        <SelectField
                            label="Rol"
                            name="role"
                            options={ROLE_OPTIONS}
                            value={data.role}
                            onChange={(event) => setData('role', event.target.value)}
                            error={errors.role}
                        />

                        <Button type="submit" loading={processing} disabled={usage.isAtLimit} className="sm:mb-1">
                            Enviar invitación
                        </Button>
                    </form>

                    <p className="text-xs opacity-70">
                        Recibirá un enlace que vale 48 horas y una sola vez. La invitación ocupa un sitio de tu plan
                        desde que se envía.
                    </p>
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <h2 className="card-title text-base">Personas con acceso</h2>

                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th scope="col">Persona</th>
                                    <th scope="col">Rol</th>
                                    <th scope="col">Estado</th>
                                    <th scope="col" className="text-right">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {members.map((member) => (
                                    <TeamRow key={member.id} member={member} atLimit={usage.isAtLimit} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </BackofficeLayout>
    );
}

/**
 * Una fila del equipo, con lo que se puede hacer sobre ella.
 *
 * Las acciones dependen del estado y son excluyentes: a una invitación
 * pendiente se le reenvía o se le revoca; a un miembro con acceso se le
 * desactiva; a uno desactivado se le reactiva. No hay ninguna fila con las
 * cuatro.
 */
function TeamRow({ member, atLimit }: { member: TeamMember; atLimit: boolean }) {
    const act = (url: string, method: 'post' | 'delete' = 'post') =>
        router.visit(url, { method, preserveScroll: true });

    const cannotDisable = member.isSelf || member.isLastOwner;

    const disableReason = member.isSelf
        ? 'No puedes desactivar tu propia cuenta.'
        : 'Es el último dueño activo: asciende antes a otra persona.';

    return (
        <tr className={member.isActive ? undefined : 'opacity-60'}>
            <td>
                <div className="flex flex-col">
                    <span className="font-medium">{member.name}</span>
                    <span className="text-xs opacity-70">{member.email}</span>
                </div>
            </td>

            <td>{member.role === 'owner' ? 'Dueño' : 'Empleado'}</td>

            <td>
                <div className="flex flex-wrap gap-1">
                    {member.isActive ? null : <Badge tone="neutral">Inactivo</Badge>}
                    {member.isPending ? <Badge tone="warning" outline>Invitación pendiente</Badge> : null}
                    {member.isActive && !member.isPending ? <Badge tone="success" outline>Con acceso</Badge> : null}
                </div>
            </td>

            <td>
                <div className="flex flex-wrap justify-end gap-1">
                    {member.isPending ? (
                        <>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => act(`/backoffice/equipo/${member.id}/reenviar`)}
                            >
                                Reenviar enlace
                            </Button>

                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => act(`/backoffice/equipo/${member.id}`, 'delete')}
                            >
                                Revocar
                            </Button>
                        </>
                    ) : null}

                    {member.isActive && !member.isPending ? (
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={cannotDisable}
                            title={cannotDisable ? disableReason : undefined}
                            onClick={() => act(`/backoffice/equipo/${member.id}/desactivar`)}
                        >
                            Desactivar
                        </Button>
                    ) : null}

                    {member.isActive ? null : (
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={atLimit}
                            title={atLimit ? 'Tu plan no admite más usuarios activos.' : undefined}
                            onClick={() => act(`/backoffice/equipo/${member.id}/activar`)}
                        >
                            Reactivar
                        </Button>
                    )}
                </div>
            </td>
        </tr>
    );
}
