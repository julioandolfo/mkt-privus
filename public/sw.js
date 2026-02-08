/**
 * MKT Privus - Service Worker para Push Notifications
 */

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let data = {
        title: 'MKT Privus',
        body: 'Nova notificacao',
        icon: '/favicon.ico',
        url: '/',
    };

    try {
        if (event.data) {
            const payload = event.data.json();
            if (payload.title) data.title = payload.title;
            if (payload.body) data.body = payload.body;
            if (payload.icon) data.icon = payload.icon;
            if (payload.url) data.url = payload.url;
        }
    } catch (e) {
        // Se não for JSON, usar o texto como body
        if (event.data) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: '/favicon.ico',
        vibrate: [200, 100, 200],
        data: {
            url: data.url,
            timestamp: Date.now(),
        },
        actions: [
            { action: 'open', title: 'Abrir' },
            { action: 'close', title: 'Fechar' },
        ],
        tag: 'mkt-privus-' + Date.now(),
        renotify: true,
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const url = event.notification.data?.url || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Se já tem uma aba aberta, focar nela
            for (const client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            // Senão, abrir nova aba
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});

self.addEventListener('notificationclose', (event) => {
    // Analytics de notificação descartada, se necessário
});
