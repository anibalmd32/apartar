import { SectionCard } from '@/components/ui/section-card';

export interface OrganizationProfileData {
    readonly ownerName: string | null;
    readonly ownerEmail: string | null;
    readonly phone: string | null;
    readonly document: string | null;
    readonly storefrontUrl: string | null;
    readonly timezone: string | null;
}

export function OrganizationProfile({ profile }: { profile: OrganizationProfileData }) {
    const rows: [string, string | null][] = [
        ['Dueño/a', profile.ownerName],
        ['Correo', profile.ownerEmail],
        ['Teléfono', profile.phone],
        ['RIF', profile.document],
        ['Vitrina', profile.storefrontUrl],
        ['Zona horaria', profile.timezone],
    ];

    return (
        <SectionCard title="Perfil" aria-label="Perfil">
            <dl className="flex flex-col gap-2.5 text-[13px]">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-3">{label}</dt>
                        <dd className="min-w-0 truncate text-right font-semibold">{value ?? '—'}</dd>
                    </div>
                ))}
            </dl>
        </SectionCard>
    );
}
