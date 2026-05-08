const CACHE_NAME = 'easywash-v2';
const urlsToCache = [
  './',
  './dashboard.php',
  './login.php',
  './offline.php',
  './assets/icon-192x192.png',
  './assets/icon-512x512.png',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
  'https://code.jquery.com/jquery-3.6.0.min.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'
];

// Network-first, falling back to cache strategy for HTML pages
const networkFirst = [
  './dashboard.php',
  './login.php',
  './'
];

// Cache-first strategy for static assets
const cacheFirst = [
  './assets/',
  'https://cdn.jsdelivr.net',
  'https://cdnjs.cloudflare.com',
  'https://code.jquery.com'
];

// Install event - cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

// Fetch event handler
self.addEventListener('fetch', event => {
  const requestUrl = new URL(event.request.url);
  
  // Skip non-GET requests and chrome-extension requests
  if (event.request.method !== 'GET' || requestUrl.protocol === 'chrome-extension:') {
    return;
  }

  // Network first for HTML pages
  if (networkFirst.some(url => requestUrl.pathname.startsWith(url))) {
    event.respondWith(
      fetch(event.request)
        .then(networkResponse => {
          // Cache the response
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME)
            .then(cache => cache.put(event.request, responseToCache));
          return networkResponse;
        })
        .catch(() => {
          // If network fails, try cache
          return caches.match(event.request)
            .then(cachedResponse => {
              // If not in cache, show offline page for HTML requests
              if (!cachedResponse && event.request.headers.get('accept').includes('text/html')) {
                return caches.match('./offline.php');
              }
              return cachedResponse;
            });
        })
    );
    return;
  }

  // Cache first for static assets
  if (cacheFirst.some(url => requestUrl.href.startsWith(url))) {
    event.respondWith(
      caches.match(event.request)
        .then(cachedResponse => {
          // Return cached response if found
          if (cachedResponse) {
            return cachedResponse;
          }
          // Otherwise fetch from network
          return fetch(event.request)
            .then(response => {
              // Cache the response
              const responseToCache = response.clone();
              caches.open(CACHE_NAME)
                .then(cache => cache.put(event.request, responseToCache));
              return response;
            });
        })
    );
    return;
  }

  // For other requests, try network first, then cache
  event.respondWith(
    fetch(event.request)
      .then(response => {
        // If valid response, cache it
        if (response.status === 200) {
          const responseToCache = response.clone();
          caches.open(CACHE_NAME)
            .then(cache => cache.put(event.request, responseToCache));
        }
        return response;
      })
      .catch(() => {
        // If network fails, try cache
        return caches.match(event.request)
          .then(cachedResponse => {
            // If not in cache and it's an HTML request, show offline page
            if (!cachedResponse && event.request.headers.get('accept').includes('text/html')) {
              return caches.match('./offline.php');
            }
            return cachedResponse;
          });
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  const cacheWhitelist = [CACHE_NAME];
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheWhitelist.indexOf(cacheName) === -1) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});
