import { Avatar } from '@/components/shell/avatar';
import { Button } from '@/components/ui/button';
import { IconTextField } from '@/components/ui/icon-text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface AcceptInvitationProps {
    token: string;
    name: string;
    email: string;
    organizationName: string | null;
    role?: 'owner' | 'member';
    expiresInHours?: number;
    hasAccount?: boolean;
}

interface AcceptForm {
    [key: string]: string;
    password: string;
    password_confirmation: string;
}

export default function AcceptInvitation({ token, name, email, organizationName, role, expiresInHours, hasAccount = false }: AcceptInvitationProps) {
    const { data, setData, post, processing, errors, reset } = useForm<AcceptForm>({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(`/invitacion/${token}`, {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AccessLayout
            title={hasAccount ? `¡Hola, ${name}! Únete con tu cuenta` : `¡Hola, ${name}! Elige tu contraseña`}
            header={
                organizationName === null ? null : (
                    <div className="bg-surface-2 flex items-center gap-3 rounded-[14px] p-3.5">
                        <Avatar name={organizationName} size={44} tone="accent" />
                        <div className="min-w-0">
                            <p className="truncate text-[15px] font-bold">{organizationName}</p>
                            <p className="text-ink-3 text-xs">
                                {role === 'owner'
                                    ? 'Te invita como Dueño'
                                    : role === 'member'
                                      ? 'Te invita como Miembro del equipo'
                                      : 'Te invita a su equipo'}
                            </p>
                        </div>
                    </div>
                )
            }
        >
            <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
                <IconTextField label="Correo" name="email" type="email" value={email} readOnly disabled />

                {hasAccount ? (
                    <IconTextField
                        label="Tu contraseña de Apartar"
                        name="password"
                        type="password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        error={errors.password}
                        hint="Ya eres del equipo de otro negocio: entra con la contraseña de siempre."
                        autoComplete="current-password"
                        autoFocus
                        required
                    />
                ) : (
                    <>
                        <IconTextField
                            label="Contraseña"
                            name="password"
                            type="password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            error={errors.password}
                            placeholder="Mínimo 12 caracteres"
                            hint="Con mayúsculas, minúsculas y números."
                            autoComplete="new-password"
                            autoFocus
                            required
                        />

                        <IconTextField
                            label="Repite la contraseña"
                            name="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                            autoComplete="new-password"
                            required
                        />
                    </>
                )}

                <Button type="submit" block loading={processing} className="h-12 text-[15px]">
                    Unirme al equipo
                </Button>

                {expiresInHours !== undefined ? (
                    <p className="text-ink-3 text-center text-xs">
                        Esta invitación vence en {expiresInHours <= 1 ? 'menos de una hora' : `${expiresInHours} horas`}.
                    </p>
                ) : null}
            </form>
        </AccessLayout>
    );
}
