self.addEventListener('push', event => {
    let payload;
    try { payload = event.data.json(); } catch { return; }
    event.waitUntil(self.registration.showNotification(payload.title || 'Sithi Nem', {
        body: payload.body,
        tag: payload.tag,
        icon: '/apple-touch-icon.png',
        data: { url: payload.url },
    }));
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/admin/commandes', self.location.origin);
    if (target.origin !== self.location.origin) return;
    event.waitUntil(clients.openWindow(target.href));
});
