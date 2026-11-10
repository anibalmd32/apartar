import { SectionCard } from '@/components/ui/section-card';
import { RateSourceField } from '@/features/currency/components/RateSourceField';
import { type RateSourceChoices } from '@/types/exchange';

interface CatalogRateSectionProps {
    choices: RateSourceChoices;
    rateSource: string;
    onRateSourceChange: (value: string) => void;
    error?: string;
}

export function CatalogRateSection({ choices, rateSource, onRateSourceChange, error }: CatalogRateSectionProps) {
    return (
        <SectionCard title="Tasa" subtitle="Con qué dólar se calculan los bolívares de este catálogo.">
            <RateSourceField
                choices={choices}
                value={rateSource}
                onChange={onRateSourceChange}
                error={error}
                hint="Tus clientes solo ven el precio en bolívares. Un producto puede tener su propia tasa y esa manda sobre la del catálogo."
            />
        </SectionCard>
    );
}
