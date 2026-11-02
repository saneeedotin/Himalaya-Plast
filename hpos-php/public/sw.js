// Service worker for HPOS: shows a Web Push notification when one arrives, even with the app closed,
// and opens the right page when it is clicked. It never caches pages or touches the network.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'HPOS', body: event.data ? event.data.text() : '' }; }
  var critical = data.priority === 'CRITICAL';
  event.waitUntil(self.registration.showNotification(data.title || 'HPOS', {
    body: data.body || '',
    tag: data.tag || undefined,           // the same tag replaces an older notification instead of stacking
    data: { url: data.url || self.registration.scope },
    requireInteraction: critical,         // critical ones stay until dismissed
    renotify: !!data.tag
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = new URL((event.notification.data && event.notification.data.url) || self.registration.scope, self.location.origin).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
    for (var i = 0; i < windows.length; i++) {
      if ('focus' in windows[i] && windows[i].url.indexOf(self.registration.scope) === 0) {
        return windows[i].navigate(target).then(function (w) { return w && w.focus(); });
      }
    }
    return self.clients.openWindow(target);
  }));
});
