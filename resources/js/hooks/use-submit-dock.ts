import { useInView } from '@/hooks/use-in-view';

export function useSubmitDock(needed: boolean) {
    const [headerRef, headerInView] = useInView<HTMLElement>();

    return { headerRef, sticky: needed && !headerInView };
}
