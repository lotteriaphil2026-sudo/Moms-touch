self.addEventListener('push', event => {
    if (!event.data) {
        return;
    }

    const notification = event.data.json();
    event.waitUntil(self.registration.showNotification(notification.title, {
        body: notification.body,
        icon: '/unnamed.png',
        data: { url: notification.url },
    }).then(async () => {
        if (typeof self.navigator.setAppBadge !== 'function') {
            return;
        }

        try {
            if (Number.isSafeInteger(notification.count) && notification.count > 0) {
                await self.navigator.setAppBadge(notification.count);
            } else if (typeof self.navigator.clearAppBadge === 'function') {
                await self.navigator.clearAppBadge();
            }
        } catch (error) {
            console.warn('Home screen badge could not be updated.', error);
        }
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/#/wallet', self.location.origin);
    if (target.origin !== self.location.origin) {
        return;
    }

    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
        for (const client of windowClients) {
            if (new URL(client.url).origin === self.location.origin) {
                return client.navigate(target.href).then(() => client.focus());
            }
        }

        return clients.openWindow(target.href);
    }));
});
