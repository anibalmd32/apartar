import { TextField } from '@/components/ui/text-field';
import { useSlugAvailability } from '@/features/marketing/hooks/useSlugAvailability';
import { suggestSlug } from '@/lib/slug';
import { useState } from 'react';

export interface OrganizationIdentity {
    organization_name: string;
    organization_slug: string;
}

interface OrganizationIdentityFieldsProps {
    value: OrganizationIdentity;
    onChange: (next: OrganizationIdentity) => void;
    errors: Partial<Record<keyof OrganizationIdentity, string>>;
    slugEndpoint?: string;
}

export function OrganizationIdentityFields({ value, onChange, errors, slugEndpoint }: OrganizationIdentityFieldsProps) {
    const [slugEdited, setSlugEdited] = useState(false);
    const availability = useSlugAvailability(value.organization_slug, slugEndpoint);

    const changeName = (name: string) =>
        onChange({ organization_name: name, organization_slug: suggestSlug(name, value.organization_slug, slugEdited) });

    const changeSlug = (slug: string) => {
        setSlugEdited(true);
        onChange({ ...value, organization_slug: slug });
    };

    return (
        <>
            <TextField
                label="Nombre de tu negocio"
                name="organization_name"
                value={value.organization_name}
                onChange={(event) => changeName(event.target.value)}
                error={errors.organization_name}
                autoComplete="organization"
                autoFocus
                required
            />

            <TextField
                label="Enlace de tu catálogo"
                name="organization_slug"
                prefix="apartar.store/"
                value={value.organization_slug}
                onChange={(event) => changeSlug(event.target.value)}
                error={errors.organization_slug}
                hint={
                    availability.state === 'free' ? (
                        <span className="text-success font-semibold">¡Disponible!</span>
                    ) : availability.state === 'taken' ? (
                        <span className="text-warning">
                            Ya está en uso.
                            {availability.suggestion !== null ? (
                                <>
                                    {' '}
                                    <button
                                        type="button"
                                        className="text-primary-ink cursor-pointer font-semibold underline"
                                        onClick={() => changeSlug(availability.suggestion ?? '')}
                                    >
                                        Usar {availability.suggestion}
                                    </button>
                                </>
                            ) : null}
                        </span>
                    ) : (
                        'Lo compartes por WhatsApp. Puedes cambiarlo mientras no publiques un catálogo.'
                    )
                }
                inputMode="url"
                required
            />
        </>
    );
}
