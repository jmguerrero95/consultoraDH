// Service Worker for Consultora DH PWA
//
// Three rules govern everything below:
//
//   1. No authenticated API response is ever cached. `/api/` requests go
//      straight to the network and are never written to a cache.
//   2. Nothing is precached by guessing a filename. The build hashes asset
//      names, so a literal pattern like `/build/assets/app-*.js` matches
//      nothing: `cache.addAll()` has no glob support and rejects the whole
//      install when a single entry fails, which left the worker uninstalled.
//      Hashed assets are content-addressed and immutable, so they are cached
//      the first time they are actually requested instead.
//   3. Only the application shell is precached, and only when it is there.

const CACHE_NAME = 'consultora-dh-v2';
const SHELL_URL = '/';

// Precache entries are literal paths that must exist at install time.
const PRECACHE_URLS = ['/', '/manifest.json'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      // Added one by one: a single unreachable entry must not reject the
      // install and leave the site with no service worker at all.
      await Promise.all(
        PRECACHE_URLS.map(async (url) => {
          try {
            await cache.add(new Request(url, { cache: 'reload' }));
          } catch {
            // Offline at install time, or the entry is not served here.
          }
        })
      );
    })
  );
  self.skipWaiting();
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    })
  );
  self.clients.claim();
});

// Fetch event.
//
// The order matters: the API rule comes first, so nothing later can ever be
// read as permission to cache an authenticated response.
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Only GET is cacheable.
  if (request.method !== 'GET') {
    return;
  }

  // Cross-origin is not ours to store.
  if (url.origin !== self.location.origin) {
    return;
  }

  // Never cache an authenticated API response. Not on the way in either: a
  // cached API body would be replayed to whoever opens the app next.
  if (url.pathname === '/api' || url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request).catch(
        () => new Response('Offline', { status: 503, statusText: 'Service Unavailable' })
      )
    );
    return;
  }

  // Hashed build output and icons are immutable: cache first is safe and fast.
  if (
    url.pathname.startsWith('/build/') ||
    url.pathname.startsWith('/icons/') ||
    url.pathname === '/manifest.json'
  ) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Navigations always prefer the network, and the offline fallback is the
  // public shell rather than a copy of whichever authenticated page happened to
  // be visited last. The shell is the same document for every route; the data
  // it renders comes from the API, which is never cached.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Only the shell is stored, and only when it is really the shell.
          if (response.ok && url.pathname === SHELL_URL) {
            const copy = response.clone();
            void caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }

          return response;
        })
        .catch(async () => {
          const cached = await caches.match(SHELL_URL);
          return cached ?? new Response('Offline', { status: 503 });
        })
    );
    return;
  }

  // Everything else: network, with the cache as an offline safety net.
  event.respondWith(
    fetch(request).catch(() => caches.match(request))
  );
});

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) {
    return cached;
  }

  const response = await fetch(request);

  if (response.ok && response.type === 'basic') {
    const copy = response.clone();
    void caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
  }

  return response;
}

// Push notification event
self.addEventListener('push', (event) => {
  if (!event.data) {
    return;
  }

  const data = event.data.json();

  const options = {
    body: data.body || 'Nuevo mensaje de soporte',
    icon: '/icons/icon-192x192.png',
    badge: '/icons/badge-72x72.png',
    vibrate: [200, 100, 200],
    data: {
      route: data.route || '/portal/soporte',
    },
    actions: [
      { action: 'open', title: 'Ver' },
      { action: 'close', title: 'Cerrar' },
    ],
    requireInteraction: true,
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'Consultora DH', options)
  );
});

// Notification click event
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'close') {
    return;
  }

  const route = event.notification.data?.route || '/portal/soporte';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // Try to find existing window
      for (const client of clientList) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.postMessage({ type: 'navigate', route });
          return client.focus();
        }
      }

      // Open new window
      if (clients.openWindow) {
        return clients.openWindow(route);
      }
    })
  );
});
