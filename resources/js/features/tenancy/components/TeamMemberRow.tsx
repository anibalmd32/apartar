import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { type OrganizationRole, type TeamMember } from '@/types/backoffice';
import { router } from '@inertiajs/react';

const ROLE_LABELS: Record<OrganizationRole, string> = {
    owner: 'Dueño',
    member: 'Empleado',
};

const ROLE_OPTIONS = [
    { value: 'member', label: ROLE_LABELS.member },
    { value: 'owner', label: ROLE_LABELS.owner },
];

interface TeamMemberRowProps {
    member: TeamMember;
    atLimit: boolean;
}

function lastAccess(member: TeamMember): string {
    if (member.isPending) {
        return member.invitedAt ? `Invitado ${ago(member.invitedAt).toLowerCase()}` : 'Invitación pendiente';
    }

    return member.lastLoginAt ? ago(member.lastLoginAt) : 'Nunca';
}

function ago(iso: string): string {
    const minutes = Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60_000));

    if (minutes < 5) {
        return 'Ahora';
    }

    if (minutes < 60) {
        return `Hace ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);

    return hours < 24 ? `Hace ${hours} h` : `Hace ${Math.floor(hours / 24)} días`;
}

export function TeamMemberRow({ member, atLimit }: TeamMemberRowProps) {
    const act = (path: string, method: 'post' | 'delete' = 'post', data: Record<string, string> = {}) =>
        router.visit(`/backoffice/equipo/${member.id}${path}`, { method, data, preserveScroll: true });

    const cannotDisable = member.isSelf || member.isLastOwner;

    const disableReason = member.isSelf ? 'No puedes desactivar tu propia cuenta.' : 'Es el último dueño activo: asciende antes a otra persona.';

    return (
        <tr className={member.isActive ? undefined : 'opacity-60'}>
            <td>
                <div className="flex items-center gap-3">
                    <Avatar name={member.name} size={32} tone={member.role === 'owner' ? 'primary' : 'accent'} />
                    <div className="flex min-w-0 flex-col">
                        <span className="flex items-center gap-1.5 font-semibold">
                            {member.name}
                            {member.isSelf ? <span className="badge badge-primary badge-count">Tú</span> : null}
                        </span>
                        <span className="text-ink-3 text-xs">{member.email}</span>
                    </div>
                </div>
            </td>

            <td>
                {member.isActive ? (
                    <SelectField
                        label={`Rol de ${member.name}`}
                        hideLabel
                        inline
                        className="select-sm"
                        value={member.role}
                        disabled={member.isLastOwner}
                        title={member.isLastOwner ? 'Es el último dueño activo: asciende antes a otra persona.' : undefined}
                        options={ROLE_OPTIONS}
                        onChange={(event) => act('/rol', 'post', { role: event.target.value })}
                    />
                ) : (
                    ROLE_LABELS[member.role]
                )}
            </td>

            <td>
                <div className="flex flex-wrap gap-1">
                    {member.isActive ? null : <Badge tone="neutral">Inactivo</Badge>}
                    {member.isPending ? <Badge tone="warning">Invitación pendiente</Badge> : null}
                    {member.isActive && !member.isPending ? <Badge tone="success">Con acceso</Badge> : null}
                </div>
            </td>

            <td className="text-ink-2 whitespace-nowrap">{lastAccess(member)}</td>

            <td>
                <div className="flex flex-wrap justify-end gap-1">
                    {member.isPending ? (
                        <>
                            <Button size="sm" variant="ghost" onClick={() => act('/reenviar')}>
                                Reenviar enlace
                            </Button>

                            <Button size="sm" variant="ghost" onClick={() => act('', 'delete')}>
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
                            onClick={() => act('/desactivar')}
                        >
                            Desactivar
                        </Button>
                    ) : null}

                    {member.isActive ? null : (
                        <Button
                            size="sm"
                            variant="soft"
                            disabled={atLimit}
                            title={atLimit ? 'Tu plan no admite más usuarios activos.' : undefined}
                            onClick={() => act('/activar')}
                        >
                            Reactivar
                        </Button>
                    )}
                </div>
            </td>
        </tr>
    );
}
