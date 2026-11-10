import { SectionCard } from '@/components/ui/section-card';
import { CatalogSafeguardToggle } from '@/features/catalogs/components/CatalogSafeguardToggle';
import { RateSourceField } from '@/features/currency/components/RateSourceField';
import { type RateSourceChoices } from '@/types/exchange';

interface CatalogRateSectionProps {
    choices: RateSourceChoices;
    rateSource: string;
    onRateSourceChange: (value: string) => void;
    safeguard: boolean;
    onSafeguardChange: (value: boolean) => void;
    error?: string;
}

export function CatalogRateSection({ choices, rateSource, onRateSourceChange, safeguard, onSafeguardChange, error }: CatalogRateSectionProps) {
    const source = rateSource === '' ? choices.businessSource : rateSource;
    const label = choices.options.find((option) => option.value === source)?.label ?? source;

    return (
        <SectionCard title="Tasa y resguardo" subtitle="Con qué dólar se calculan los bolívares de este catálogo y cómo guardas lo que cobras.">
            <RateSourceField
                choices={choices}
                value={rateSource}
                onChange={onRateSourceChange}
                error={error}
                hint="Tus clientes solo ven el precio en bolívares. Un producto puede tener su propia tasa y esa manda sobre la del catálogo."
            />
            <CatalogSafeguardToggle checked={safeguard} onChange={onSafeguardChange} rateLabel={label} />
        </SectionCard>
    );
}
