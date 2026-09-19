import { ExchangeRateForm } from '@/features/tenancy/components/ExchangeRateForm';
import { OperationSettingsForm } from '@/features/tenancy/components/OperationSettingsForm';
import { OrganizationIdentityForm } from '@/features/tenancy/components/OrganizationIdentityForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type OrganizationBrand, type OrganizationIdentity, type OrganizationSettings, type SettingDefinition } from '@/types/backoffice';
import { type RateSource, type RateView } from '@/types/exchange';

/**
 * Ajustes del negocio (`REQ-ORG-006`).
 *
 * Tres secciones y tres guardados. Identidad es otra capacidad de
 * `REQ-ORG-003` RB-1 —`Branding`, con su propia policy—; la tasa comparte
 * capacidad con la operación pero es **otra operación**: la fuente y la tasa
 * manual son un par que solo tiene sentido guardado junto (`REQ-FX-003` SP-1),
 * y su sección necesita datos que las demás no —la tasa vigente de cada
 * fuente, para poder comparar antes de elegir (RB-6)—.
 *
 * Para quien la usa es un solo sitio: «la configuración de mi negocio», y por
 * eso comparten pantalla.
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
    rateSources: RateView[];
}

export default function SettingsIndex({ settings, definitions, identity, brand, rateSources }: SettingsPageProps) {
    const source = (settings.exchange_rate_source ?? 'bcv') as RateSource;
    const manualRate =
        settings.exchange_rate_manual === null || settings.exchange_rate_manual === undefined ? '' : String(settings.exchange_rate_manual);

    return (
        <BackofficeLayout
            title="Ajustes"
            description="Cómo opera tu negocio y cómo se ve. Los cambios afectan a lo que venga después, no a lo ya creado."
        >
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header>
                        <h2 className="card-title text-base">Tasa de cambio</h2>
                        <p className="text-sm opacity-70">
                            Con qué tasa se proyectan tus precios en bolívares. La elección es tuya; la tasa la ingesta la plataforma.
                        </p>
                    </header>

                    <ExchangeRateForm source={source} manualRate={manualRate} rateSources={rateSources} />
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header>
                        <h2 className="card-title text-base">Operación</h2>
                        <p className="text-sm opacity-70">Plazos y frecuencia de cuotas. Cada campo dice a qué alcanza el cambio.</p>
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
