
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload = {};

    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Apartar', body: event.data.text() };
    }

    const title = payload.title || 'Apartar';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            tag: payload.tag,
            data: payload.data || {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/mi-cuenta';

    event.waitUntil(self.clients.openWindow(url));
});
