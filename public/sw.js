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

const CACHE_NAME = 'consultora-dh-v3';
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
    event.respondWith(fetch(request).catch(() => offlineResponse()));

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

          // The shell may itself not be cached — an install that happened offline, or a
          // storage eviction. Returning `undefined` here produced the same
          // `Failed to convert value to 'Response'` on a navigation.
          return cached ?? offlineResponse();
        })
    );
    return;
  }

  // Everything else: network, with the cache as an offline safety net.
  //
  // `caches.match()` resolves to `undefined` when nothing matched, and `respondWith()` must
  // be given a `Response`. Resolving to `undefined` is the `Failed to convert value to
  // 'Response'` error in the console — it rejected the promise the worker had already handed
  // to the browser, so the request failed with no body and no status rather than with the
  // offline answer the fallback was written to give.
  //
  // The `undefined` case is exactly "this resource was never cached", which for a
  // non-navigation request means there is nothing offline to show: a 503 saying so is the
  // honest answer, and it is the same answer the `/api/` branch above already gives.
  event.respondWith(
    fetch(request).catch(async () => (await caches.match(request)) ?? offlineResponse())
  );
});

/** A `Response`, never `undefined` — see the fallback above. */
function offlineResponse() {
  return new Response('Offline', {
    status: 503,
    statusText: 'Service Unavailable',
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
}

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

  let data;
  try {
    data = event.data.json();
  } catch {
    // A payload that is not JSON is not worth showing an empty notification for.
    return;
  }

  const options = {
    body: data.body || 'Hay algo nuevo en Consultora DH.',
    icon: '/icons/icon-192x192.png',
    badge: '/icons/badge-72x72.png',
    vibrate: [200, 100, 200],
    // The deep link is carried through `url`, which is what `WebPushService`
    // actually sends. It used to be read as `route`, which that service never
    // sets, so every notification - a finished document included - opened the
    // support inbox. The two were written apart from each other and nothing
    // tested the join.
    data: {
      url: safeDestination(data.url),
      taskId: data.task_id ?? null,
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

/**
 * Where a notification is allowed to send somebody.
 *
 * A push payload is data from outside the application: it decides what URL a
 * phone opens. Only a relative path inside this origin is honoured - an
 * absolute URL, a protocol-relative one, or anything with another origin is
 * discarded, because a payload must never be able to turn a notification into a
 * trip to somewhere else.
 */
function safeDestination(candidate) {
  if (typeof candidate !== 'string' || candidate === '') {
    return '/asistente';
  }

  // Must be a single leading slash: `//evil.example` and `\\evil` are
  // protocol-relative URLs, not paths on this site.
  if (!candidate.startsWith('/') || candidate.startsWith('//') || candidate.startsWith('/\\')) {
    return '/asistente';
  }

  return candidate;
}

// Notification click event
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'close') {
    return;
  }

  // Re-validated here as well as when the notification was shown: the payload
  // sits in the notification store, which outlives this worker.
  const target = safeDestination(event.notification.data?.url);

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          // Handed to the app rather than navigating the window directly, so
          // the router owns the transition and the session survives it.
          client.postMessage({ type: 'navigate', url: target });
          return client.focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(target);
      }
    })
  );
});

// Background Sync: replay writes that were made while offline.
//
// The outbox is the page's, not ours: it posts a request here when a write
// fails for network reasons, and the browser decides *when* to call `sync` -
// which is the only reliable signal that connectivity is back. Polling for
// `navigator.onLine` is not one, because it reports that a connection exists
// long before it reaches anything.
self.addEventListener('sync', (event) => {
  if (event.tag === 'cdh-outbox') {
    event.waitUntil(replayOutbox());
  }
});

async function replayOutbox() {
  const requests = await readOutbox();

  for (const entry of requests) {
    try {
      const response = await fetch(entry.url, {
        method: entry.method || 'POST',
        headers: entry.headers || {},
        body: entry.body ?? null,
        // Same-origin and credentialed, because these are writes made by a
        // signed-in person and must be authorised exactly as they were the
        // first time.
        credentials: 'include',
      });

      // Anything that is not 2xx is a refusal the person needs to see - a 422
      // or a 403 will not fix itself by being retried, and dropping it would
      // lose it silently. Only a success clears the entry.
      if (response.ok) {
        await deleteOutboxEntry(entry.id);
      } else if (response.status >= 400 && response.status < 500) {
        await deleteOutboxEntry(entry.id);
        await notifyClient({
          type: 'outbox-rejected',
          url: entry.url,
          status: response.status,
        });
      }
    } catch {
      // Still offline. The entry stays and the browser will call `sync` again.
      return;
    }
  }
}

function openOutbox() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('cdh-outbox', 1);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('requests')) {
        db.createObjectStore('requests', { keyPath: 'id', autoIncrement: true });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function readOutbox() {
  const db = await openOutbox();

  return new Promise((resolve) => {
    const tx = db.transaction('requests', 'readonly');
    const req = tx.objectStore('requests').getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => resolve([]);
  });
}

async function deleteOutboxEntry(id) {
  const db = await openOutbox();

  return new Promise((resolve) => {
    const tx = db.transaction('requests', 'readwrite');
    tx.objectStore('requests').delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
  });
}

async function notifyClient(message) {
  const clientList = await clients.matchAll({ type: 'window', includeUncontrolled: true });

  for (const client of clientList) {
    client.postMessage(message);
  }
}

// The page hands over a write it could not make.
self.addEventListener('message', (event) => {
  const message = event.data;

  if (!message || message.type !== 'queue-request') {
    return;
  }

  event.waitUntil(
    (async () => {
      const db = await openOutbox();

      await new Promise((resolve) => {
        const tx = db.transaction('requests', 'readwrite');
        tx.objectStore('requests').add({
          url: message.url,
          method: message.method,
          headers: message.headers,
          body: message.body,
          queuedAt: Date.now(),
        });
        tx.oncomplete = () => resolve();
        tx.onerror = () => resolve();
      });

      if (self.registration.sync) {
        try {
          await self.registration.sync.register('cdh-outbox');
        } catch {
          // Registration is best effort: the page also retries on reconnect.
        }
      }
    })()
  );
});
