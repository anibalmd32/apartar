import { Alert } from '@/components/ui/alert';
import { OperatorsPanel } from '@/features/platform/components/OperatorsPanel';
import { PlatformSettingsForm } from '@/features/platform/components/PlatformSettingsForm';
import PlatformLayout from '@/layouts/platform-layout';
import { PlatformSupportTabs } from '@/layouts/platform/nav';
import { type PlatformOperatorRow, type PlatformSettingRow } from '@/types/platform';

interface SettingsIndexProps {
    settings: readonly PlatformSettingRow[];
    operators: readonly PlatformOperatorRow[];
    temporaryPassword: { name: string; email: string; password: string } | null;
}

export default function SettingsIndex({ settings, operators, temporaryPassword }: SettingsIndexProps) {
    return (
        <PlatformLayout
            title="Ajustes"
            description="Parámetros globales de la plataforma y operadores con acceso. Cada cambio queda en la auditoría."
        >
            <PlatformSupportTabs />

            {temporaryPassword ? (
                <Alert tone="warning" title={`Contraseña temporal de ${temporaryPassword.name}`}>
                    <p>
                        Entra en <strong>/plataforma/login</strong> con <strong>{temporaryPassword.email}</strong> y la contraseña{' '}
                        <code className="bg-base-100 rounded px-1.5 py-0.5 font-mono text-[13px] select-all">{temporaryPassword.password}</code>. Solo
                        se muestra ahora: compártela por un canal seguro. Tendrá que cambiarla al entrar.
                    </p>
                </Alert>
            ) : null}

            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-6">
                <PlatformSettingsForm settings={settings} />
                <OperatorsPanel operators={operators} />
            </div>
        </PlatformLayout>
    );
}
