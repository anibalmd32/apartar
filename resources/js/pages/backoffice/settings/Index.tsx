import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { ExchangeRateForm } from '@/features/tenancy/components/ExchangeRateForm';
import { OperationSettingsForm } from '@/features/tenancy/components/OperationSettingsForm';
import { OrganizationIdentityForm } from '@/features/tenancy/components/OrganizationIdentityForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type OrganizationBrand, type OrganizationIdentity, type OrganizationSettings, type SettingDefinition } from '@/types/backoffice';
import { type RateSource, type RateView } from '@/types/exchange';
import { cilBank, cilSettings, cilSwapHorizontal } from '@coreui/icons';

const SECTIONS = [
    { id: 'identidad', label: 'Identidad', icon: cilBank },
    { id: 'tasa', label: 'Tasa de cambio', icon: cilSwapHorizontal },
    { id: 'operacion', label: 'Operación', icon: cilSettings },
];

interface SettingsPageProps {
    settings: OrganizationSettings;
    definitions: SettingDefinition[];
    identity: OrganizationIdentity;
    brand: OrganizationBrand;
    rateSources: RateView[];
    rateSourceOptions: { value: RateSource; label: string }[];
}

export default function SettingsIndex({ settings, definitions, identity, brand, rateSources, rateSourceOptions }: SettingsPageProps) {
    const source = (settings.exchange_rate_source ?? 'bcv') as RateSource;
    const manualRate =
        settings.exchange_rate_manual === null || settings.exchange_rate_manual === undefined ? '' : String(settings.exchange_rate_manual);

    return (
        <BackofficeLayout
            title="Ajustes"
            description="Cómo opera tu negocio y cómo se ve. Los cambios afectan a lo que venga después, no a lo ya creado."
        >
            <div className="grid items-start gap-4 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-6">
                <nav aria-label="Secciones de ajustes" className="flex gap-1 overflow-x-auto lg:sticky lg:top-24 lg:flex-col">
                    {SECTIONS.map((section) => (
                        <a
                            key={section.id}
                            href={`#${section.id}`}
                            className="text-ink-2 hover:bg-base-100 hover:text-base-content flex h-[38px] shrink-0 items-center gap-3 rounded-[10px] px-3 text-sm font-medium"
                        >
                            <Icon icon={section.icon} size={18} />
                            {section.label}
                        </a>
                    ))}
                </nav>

                <div className="flex min-w-0 flex-col gap-4">
                    <SectionCard
                        as="section"
                        className="scroll-mt-24"
                        title="Identidad del negocio"
                        subtitle="Cómo te ven tus clientes y cómo te encontramos nosotros."
                    >
                        <div id="identidad" className="-mt-20 pt-20" />
                        <OrganizationIdentityForm identity={identity} brand={brand} />
                    </SectionCard>

                    <SectionCard
                        title="Tasa de cambio"
                        subtitle="Con qué tasa se proyectan tus precios en bolívares. La elección es tuya; la tasa la ingesta la plataforma."
                    >
                        <div id="tasa" className="-mt-20 pt-20" />
                        <ExchangeRateForm source={source} manualRate={manualRate} sourceOptions={rateSourceOptions} rateSources={rateSources} />
                    </SectionCard>

                    <SectionCard title="Operación" subtitle="Plazos y frecuencia de cuotas. Cada campo dice a qué alcanza el cambio.">
                        <div id="operacion" className="-mt-20 pt-20" />
                        <OperationSettingsForm settings={settings} definitions={definitions} />
                    </SectionCard>
                </div>
            </div>
        </BackofficeLayout>
    );
}
