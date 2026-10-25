export function watchBrokenImages(root: Document = document): void {
    root.addEventListener(
        'error',
        (event) => {
            if (event.target instanceof HTMLImageElement) {
                event.target.dataset.broken = 'true';
            }
        },
        true,
    );
}
