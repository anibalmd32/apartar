import { SectionCard } from '@/components/ui/section-card';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { type ReactNode } from 'react';

export interface OrganizationProfileData {
    readonly ownerName: string | null;
    readonly ownerEmail: string | null;
    readonly phone: string | null;
    readonly document: string | null;
    readonly storefrontUrl: string | null;
    readonly timezone: string | null;
}

export function timezoneName(timezone: string): string {
    try {
        const name = new Intl.DateTimeFormat('es-VE', { timeZone: timezone, timeZoneName: 'long' })
            .formatToParts(new Date())
            .find((part) => part.type === 'timeZoneName')?.value;
        const city = timezone.split('/').pop()?.replaceAll('_', ' ') ?? timezone;

        return name === undefined ? city : `${name.replace(/^./, (letter) => letter.toUpperCase())} (${city})`;
    } catch {
        return timezone;
    }
}

export function storefrontLabel(url: string): string {
    try {
        const parsed = new URL(url);

        return `${parsed.host}${parsed.pathname === '/' ? '' : parsed.pathname}`;
    } catch {
        return url;
    }
}

export function OrganizationProfile({ profile }: { profile: OrganizationProfileData }) {
    const rows: [string, ReactNode][] = [
        ['Dueño/a', profile.ownerName],
        ['Correo', profile.ownerEmail],
        ['Teléfono', profile.phone === null ? null : formatPhone(profile.phone)],
        ['RIF o cédula', profile.document === null ? null : formatDocumentNumber(profile.document)],
        [
            'Vitrina',
            profile.storefrontUrl === null ? null : (
                <a href={profile.storefrontUrl} target="_blank" rel="noreferrer" className="link link-primary">
                    {storefrontLabel(profile.storefrontUrl)}
                </a>
            ),
        ],
        ['Zona horaria', profile.timezone === null ? null : timezoneName(profile.timezone)],
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
