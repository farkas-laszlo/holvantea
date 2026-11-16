// Hol van tea? – minimál service worker.
// Csak a felület (HTML/CSS/JS) fájljait tartja cache-ben, hogy kezdőképernyőről
// gyorsan induljon és rossz téren se legyen teljesen fehér oldal. Az api.php és
// distance.php válaszait SOHA nem cache-eli (mindig friss nyitvatartás/idő kell).
const CACHE = 'holvantea-shell-v1';
const SHELL = ['./', './index.html', './manifest.json', './icons/icon-192.png', './icons/icon-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.pathname.endsWith('/api.php') || url.pathname.endsWith('/distance.php')) {
    return; // mindig hálózatról, sosem cache-ből
  }
  if (event.request.method !== 'GET') {
    return;
  }
  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request).catch(() => cached))
  );
});
