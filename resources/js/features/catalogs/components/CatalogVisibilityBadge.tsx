import { Badge, type BadgeTone } from '@/components/ui/badge';
import { type CatalogVisibility } from '@/types/catalog';

const LABELS: Record<CatalogVisibility, string> = {
    draft: 'Borrador',
    listed: 'En tu vitrina',
    unlisted: 'Solo por enlace',
    expired: 'Caducado',
};

const TONES: Record<CatalogVisibility, BadgeTone> = {
    draft: 'neutral',
    listed: 'success',
    unlisted: 'info',
    expired: 'warning',
};

export function CatalogVisibilityBadge({ visibility, className }: { visibility: CatalogVisibility; className?: string }) {
    return (
        <Badge tone={TONES[visibility]} className={className}>
            {LABELS[visibility]}
        </Badge>
    );
}
