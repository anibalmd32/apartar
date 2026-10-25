import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Meter } from '@/components/ui/meter';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { TeamMemberRow } from '@/features/tenancy/components/TeamMemberRow';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type TeamMember } from '@/types/backoffice';
import { type UsageState } from '@/types/plan';
import { cilEnvelopeClosed } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

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
            description="Invita a quienes te ayudan a vender. Los empleados no ven la facturación ni los ajustes."
            actions={
                <div className="bg-base-100 border-line shadow-card flex w-64 flex-col gap-2 rounded-xl border px-4 py-3">
                    <Meter
                        label="Usuarios del plan"
                        value={formatUsage(usage.used, usage.limit)}
                        percentage={usage.percentage}
                        warn={usage.isAtLimit || (usage.percentage ?? 0) >= 80}
                    />
                </div>
            }
        >
            {usage.isAtLimit ? (
                <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                    <span>
                        Tu plan no admite más usuarios. Las invitaciones pendientes también ocupan sitio: puedes revocar alguna o cambiar de plan.
                    </span>

                    <a className="btn btn-sm" href="/backoffice/suscripcion">
                        Ver planes
                    </a>
                </Alert>
            ) : null}

            {!usage.isAtLimit ? <LimitBanner metric="users" /> : null}

            <section aria-label="Invitar a alguien" className="bg-base-100 border-line shadow-card flex flex-col gap-2 rounded-2xl border p-4">
                <form className="grid grid-cols-1 items-start gap-x-2.5 md:grid-cols-[1fr_1fr_1fr_auto]" onSubmit={submit} noValidate>
                    <TextField
                        label="Nombre"
                        name="name"
                        placeholder="Nombre y apellido"
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
                        placeholder="correo@ejemplo.com"
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

                    <Button type="submit" loading={processing} disabled={usage.isAtLimit} className="md:mt-[34px]">
                        <Icon icon={cilEnvelopeClosed} />
                        Enviar invitación
                    </Button>
                </form>

                <p className="text-ink-3 text-xs">
                    Recibirá un enlace que vale 48 horas y una sola vez. La invitación ocupa un sitio de tu plan desde que se envía.
                </p>
            </section>

            <section aria-label="Personas con acceso" className="table-frame">
                <table className="table">
                    <thead>
                        <tr>
                            <th scope="col">Persona</th>
                            <th scope="col">Rol</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Último acceso</th>
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
            </section>

            <section aria-label="Qué puede hacer cada rol" className="bg-surface-2 grid grid-cols-1 gap-5 rounded-2xl p-5 text-[13px] md:grid-cols-2">
                <div className="flex flex-col gap-1">
                    <h2 className="text-sm font-bold">Dueño</h2>
                    <p className="text-ink-2">
                        Todo, incluida la suscripción, la facturación, el equipo, las cuentas de cobro, los ajustes y la auditoría.
                    </p>
                </div>
                <div className="flex flex-col gap-1">
                    <h2 className="text-sm font-bold">Empleado</h2>
                    <p className="text-ink-2">
                        Productos, catálogos, clientes, cobranza, comprobantes y chat. No ve la facturación ni los ajustes del negocio.
                    </p>
                </div>
            </section>
        </BackofficeLayout>
    );
}
