import { type FollowState } from '@/types/account';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export function useFollowCatalog(follow: FollowState) {
    const [optimistic, setOptimistic] = useState<{ readonly from: boolean; readonly value: boolean } | null>(null);
    const [pending, setPending] = useState(false);

    const following = optimistic !== null && optimistic.from === follow.following ? optimistic.value : follow.following;

    const options = {
        preserveScroll: true,
        onStart: () => setPending(true),
        onFinish: () => {
            setPending(false);
            setOptimistic(null);
        },
    };

    const toggle = () => {
        if (pending) {
            return;
        }

        if (!follow.signedIn) {
            router.post(follow.url, {}, options);

            return;
        }

        setOptimistic({ from: follow.following, value: !following });

        if (following) {
            router.delete(follow.url, options);
        } else {
            router.post(follow.url, {}, options);
        }
    };

    return { following, pending, needsSignIn: !follow.signedIn, toggle };
}
