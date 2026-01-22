const CACHE = "zaklady-v1";
const OFFLINE_URL = "/offline.html";

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll([
      OFFLINE_URL,
      "/",
      "/index.php"
    ]))
  );
});

self.addEventListener("fetch", (event) => {
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request).then(r => r || caches.match(OFFLINE_URL)))
  );
});
