import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { type OrganizationRole, type TeamMember } from '@/types/backoffice';
import { router } from '@inertiajs/react';

/**
 * Una fila del equipo, con todo lo que se puede hacer sobre esa persona
 * (`REQ-ORG-003`, `REQ-ORG-004`, `REQ-ORG-005`).
 *
 * Sale de `pages/backoffice/team/Index.tsx` al llegar el selector de rol:
 * `max-lines` está en 200 como **error** (`REQ-QAX-002` RB-1) y la página
 * rozaba el límite. Trocear es lo que la regla busca, y esta es la costura
 * natural — la fila ya era un componente, solo que dentro del mismo archivo—.
 * Primer inquilino de `features/` ([05 §8]).
 *
 * Las acciones dependen del estado y son excluyentes: a una invitación
 * pendiente se le reenvía o se le revoca; a quien tiene acceso se le desactiva
 * o se le cambia el rol; a uno desactivado se le reactiva.
 *
 * **Ningún control imposible está simplemente ausente**: se deshabilita con el
 * motivo al lado. Un control que desaparece deja a la persona buscándolo; uno
 * deshabilitado que explica por qué la deja sabiendo qué hacer. Y ninguno es
 * el control de acceso: eso es la policy del servidor (`REQ-ORG-003` RB-4).
 */

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
    /** El plan no admite más usuarios activos: reactivar quedaría bloqueado. */
    atLimit: boolean;
}

export function TeamMemberRow({ member, atLimit }: TeamMemberRowProps) {
    const act = (path: string, method: 'post' | 'delete' = 'post', data: Record<string, string> = {}) =>
        router.visit(`/backoffice/equipo/${member.id}${path}`, { method, data, preserveScroll: true });

    const cannotDisable = member.isSelf || member.isLastOwner;

    const disableReason = member.isSelf ? 'No puedes desactivar tu propia cuenta.' : 'Es el último dueño activo: asciende antes a otra persona.';

    return (
        <tr className={member.isActive ? undefined : 'opacity-60'}>
            <td>
                <div className="flex flex-col">
                    <span className="font-medium">{member.name}</span>
                    <span className="text-xs opacity-70">{member.email}</span>
                </div>
            </td>

            <td>
                {member.isActive ? (
                    <SelectField
                        /* La etiqueta nombra a la persona y va oculta: la
                           columna ya dice «Rol» a quien la ve, y quien navega
                           con lector de pantalla no tiene esa columna delante
                           —oiría quince «Rol» seguidos sin saber de quién—. */
                        label={`Rol de ${member.name}`}
                        hideLabel
                        className="select-sm"
                        value={member.role}
                        /* RB-3 · degradar al último dueño activo dejaría al
                           negocio sin nadie capaz de invitar ni de tocar la
                           suscripción, así que el selector se bloquea con el
                           motivo. El servidor lo vuelve a comprobar con el
                           bloqueo puesto: entre pintar y elegir cabe otra
                           petición. */
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
                    {member.isPending ? (
                        <Badge tone="warning" outline>
                            Invitación pendiente
                        </Badge>
                    ) : null}
                    {member.isActive && !member.isPending ? (
                        <Badge tone="success" outline>
                            Con acceso
                        </Badge>
                    ) : null}
                </div>
            </td>

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
                            variant="secondary"
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
