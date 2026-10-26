import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconTextField } from '@/components/ui/icon-text-field';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { PasswordRequirements } from '@/layouts/auth/password-requirements';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { cilEnvelopeClosed, cilInfo, cilLockLocked } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ProfileProps {
    profile: {
        name: string;
        email: string;
        emailVerified: boolean;
        businesses: number;
    };
}

interface ProfileForm {
    [key: string]: string;
    name: string;
    email: string;
}

interface PasswordForm {
    [key: string]: string;
    current_password: string;
    password: string;
    password_confirmation: string;
}

function ProfileDetails({ profile }: ProfileProps) {
    const form = useForm<ProfileForm>({ name: profile.name, email: profile.email });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.patch('/backoffice/perfil', { preserveScroll: true });
    };

    const changingEmail = form.data.email.trim().toLowerCase() !== profile.email;

    return (
        <SectionCard
            title="Tus datos"
            subtitle={
                profile.businesses > 1
                    ? `Son los mismos en los ${profile.businesses} negocios donde estás.`
                    : 'Con este correo entras a Apartar y recibes los avisos.'
            }
            action={profile.emailVerified ? <Badge tone="success">Correo confirmado</Badge> : <Badge tone="warning">Correo sin confirmar</Badge>}
        >
            <form className="flex flex-col gap-1" onSubmit={submit} noValidate>
                <TextField
                    label="Nombre y apellido"
                    name="name"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    error={form.errors.name}
                    autoComplete="name"
                    required
                />
                <IconTextField
                    label="Correo"
                    name="email"
                    type="email"
                    icon={cilEnvelopeClosed}
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    error={form.errors.email}
                    hint={changingEmail ? 'Te enviaremos un enlace al correo nuevo para confirmarlo.' : undefined}
                    autoComplete="email"
                    required
                />
                <div className="mt-3 flex justify-end">
                    <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                        Guardar datos
                    </Button>
                </div>
            </form>
        </SectionCard>
    );
}

function PasswordChange() {
    const form = useForm<PasswordForm>({ current_password: '', password: '', password_confirmation: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put('/backoffice/perfil/contrasena', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <SectionCard title="Contraseña" subtitle="Cámbiala cuando quieras. Vale para todos tus negocios.">
            <form className="flex flex-col gap-1" onSubmit={submit} noValidate>
                <IconTextField
                    label="Contraseña actual"
                    name="current_password"
                    type="password"
                    icon={cilLockLocked}
                    revealable
                    value={form.data.current_password}
                    onChange={(event) => form.setData('current_password', event.target.value)}
                    error={form.errors.current_password}
                    autoComplete="current-password"
                    required
                />
                <IconTextField
                    label="Contraseña nueva"
                    name="password"
                    type="password"
                    icon={cilLockLocked}
                    revealable
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    error={form.errors.password}
                    describedBy="profile-password-requirements"
                    autoComplete="new-password"
                    required
                />
                <PasswordRequirements id="profile-password-requirements" value={form.data.password} />
                <IconTextField
                    label="Repite la contraseña nueva"
                    name="password_confirmation"
                    type="password"
                    icon={cilLockLocked}
                    value={form.data.password_confirmation}
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                    required
                />
                <div className="mt-3 flex justify-end">
                    <Button type="submit" loading={form.processing} disabled={form.data.current_password === '' || form.data.password === ''}>
                        Cambiar contraseña
                    </Button>
                </div>
            </form>
        </SectionCard>
    );
}

export default function ProfileEdit({ profile }: ProfileProps) {
    return (
        <BackofficeLayout title="Mi perfil" description="Tus datos para entrar a Apartar y tu contraseña.">
            <div className="flex w-full max-w-2xl flex-col gap-4 lg:gap-6">
                <ProfileDetails profile={profile} />
                <PasswordChange />
                <p className="text-ink-3 flex items-center gap-2 text-xs">
                    <Icon icon={cilInfo} />
                    El tema claro u oscuro se cambia desde el menú de tu foto, arriba a la derecha.
                </p>
            </div>
        </BackofficeLayout>
    );
}
