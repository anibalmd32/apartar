import { OperationSettingsForm } from '@/features/tenancy/components/OperationSettingsForm';
import { OrganizationIdentityForm } from '@/features/tenancy/components/OrganizationIdentityForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type OrganizationBrand, type OrganizationIdentity, type OrganizationSettings, type SettingDefinition } from '@/types/backoffice';

/**
 * Ajustes del negocio (`REQ-ORG-006`).
 *
 * Dos secciones y dos guardados, porque son dos capacidades distintas de
 * `REQ-ORG-003` RB-1 —`Settings` y `Branding`— con sus propias policies. Para
 * quien la usa es un solo sitio: «la configuración de mi negocio», y por eso
 * comparten pantalla.
 *
 * Cada formulario vive en `features/tenancy/components/`: son largos, cambian
 * por motivos distintos y el `max-lines` de 200 de `REQ-QAX-002` RB-1 existe
 * justamente para que no acaben en el mismo archivo.
 */

interface SettingsPageProps {
    settings: OrganizationSettings;
    definitions: SettingDefinition[];
    identity: OrganizationIdentity;
    brand: OrganizationBrand;
}

export default function SettingsIndex({ settings, definitions, identity, brand }: SettingsPageProps) {
    return (
        <BackofficeLayout
            title="Ajustes"
            description="Cómo opera tu negocio y cómo se ve. Los cambios afectan a lo que venga después, no a lo ya creado."
        >
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header>
                        <h2 className="card-title text-base">Operación</h2>
                        <p className="text-sm opacity-70">Tasa, plazos y frecuencia de cuotas. Cada campo dice a qué alcanza el cambio.</p>
                    </header>

                    <OperationSettingsForm settings={settings} definitions={definitions} />
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header>
                        <h2 className="card-title text-base">Identidad</h2>
                        <p className="text-sm opacity-70">Cómo te ven tus clientes y cómo te encontramos nosotros.</p>
                    </header>

                    <OrganizationIdentityForm identity={identity} brand={brand} />
                </div>
            </section>
        </BackofficeLayout>
    );
}
