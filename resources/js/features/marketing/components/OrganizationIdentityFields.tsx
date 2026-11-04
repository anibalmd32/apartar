import { TextField } from '@/components/ui/text-field';
import { useSlugAvailability } from '@/features/marketing/hooks/useSlugAvailability';
import { suggestSlug, toSlug, toSlugWhileTyping } from '@/lib/slug';
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
        onChange({ ...value, organization_slug: toSlugWhileTyping(slug) });
    };

    const tidySlug = () => {
        const tidy = toSlug(value.organization_slug);

        if (tidy !== value.organization_slug) {
            onChange({ ...value, organization_slug: tidy });
        }
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
                label="Enlace de tu tienda"
                name="organization_slug"
                prefix="apartar.app/"
                value={value.organization_slug}
                onChange={(event) => changeSlug(event.target.value)}
                onBlur={tidySlug}
                error={errors.organization_slug}
                hint={
                    availability.state === 'free' ? (
                        <span>
                            <span className="text-success font-semibold">¡Disponible!</span> Lo compartes por WhatsApp.
                        </span>
                    ) : availability.state === 'taken' ? (
                        <span className="text-warning">
                            Ya está en uso.
                            {availability.suggestion !== null ? (
                                <>
                                    {' '}
                                    <button
                                        type="button"
                                        className="text-primary-ink touch:min-h-11 inline-flex min-h-8 cursor-pointer items-center font-semibold underline"
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
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                required
            />
        </>
    );
}
