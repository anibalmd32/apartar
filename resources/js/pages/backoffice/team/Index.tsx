import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { TeamMemberRow } from '@/features/tenancy/components/TeamMemberRow';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type TeamMember } from '@/types/backoffice';
import { type UsageState } from '@/types/plan';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Equipo: quién entra al negocio y con qué permisos
 * (`REQ-ORG-003`, `REQ-ORG-004`, `REQ-ORG-005`).
 *
 * Es la pantalla que convierte el producto en algo que usa un negocio y no una
 * sola persona, y tiene una responsabilidad que no es evidente: **decir la
 * verdad sobre lo que no se puede hacer, antes de que lo intenten**. Ningún
 * control imposible está simplemente ausente: se deshabilita con el motivo al
 * lado. Y ninguno de ellos es el control de acceso —eso es la policy del
 * servidor (`REQ-ORG-003` RB-4)—.
 *
 * Los desactivados **siguen en la lista**, marcados. Esconderlos convertiría
 * la baja lógica en un borrado a efectos prácticos y dejaría al dueño sin
 * poder reactivar a nadie (`REQ-ORG-005` RB-7).
 *
 * La fila vive en `features/tenancy/components/TeamMemberRow.tsx`: al llegar
 * el selector de rol de `T-013b`, este archivo se acercaba al `max-lines` de
 * 200 de `REQ-QAX-002` RB-1, y trocear por la costura natural es lo que esa
 * regla busca.
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

/**
 * Las etiquetas del alta explican el rol, a diferencia de las del listado: al
 * invitar se elige por primera vez y la palabra sola no dice qué implica.
 */
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
        <BackofficeLayout title="Equipo" description="Quién entra a este backoffice y con qué permisos.">
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Invitar a alguien</h2>
                        <p className="text-sm opacity-70">{formatUsage(usage.used, usage.limit)} usuarios</p>
                    </header>

                    {usage.isAtLimit ? (
                        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                            <span>
                                Tu plan no admite más usuarios. Las invitaciones pendientes también ocupan sitio: puedes revocar alguna o cambiar de
                                plan.
                            </span>

                            <a className="btn btn-sm btn-warning" href="/backoffice/suscripcion">
                                Ver planes
                            </a>
                        </Alert>
                    ) : null}

                    {/* RB-7 · entre el 80 % y el techo; en el techo, el aviso de arriba. */}
                    {!usage.isAtLimit ? <LimitBanner metric="users" /> : null}

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
                        Recibirá un enlace que vale 48 horas y una sola vez. La invitación ocupa un sitio de tu plan desde que se envía.
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
                                    <TeamMemberRow key={member.id} member={member} atLimit={usage.isAtLimit} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </BackofficeLayout>
    );
}
