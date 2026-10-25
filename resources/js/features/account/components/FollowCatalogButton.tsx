import { Button } from '@/components/ui/button';
import { useFollowCatalog } from '@/features/account/hooks/useFollowCatalog';
import { type FollowState } from '@/types/account';
import { usePage } from '@inertiajs/react';

export function FollowCatalogButton({ follow }: { follow: FollowState }) {
    const { following, pending, needsSignIn, toggle } = useFollowCatalog(follow);
    const error = usePage<{ errors?: Record<string, string> }>().props.errors?.follow;

    return (
        <div className="flex flex-col items-start gap-1">
            <Button variant={following ? 'secondary' : 'primary'} size="sm" loading={pending} aria-pressed={following} onClick={toggle}>
                {following ? 'Siguiendo' : 'Seguir este catálogo'}
            </Button>

            {needsSignIn ? <p className="text-xs opacity-70">Te enteras de lo nuevo sin que te reenvíen el enlace. Entras con Google.</p> : null}

            {error !== undefined ? (
                <p role="alert" className="text-error text-xs">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
