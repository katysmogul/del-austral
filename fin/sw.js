// ============================================================
// Del Austral — Service Worker mínimo
// ============================================================
// Este service worker existe únicamente para que el navegador
// permita "instalar" el sitio como app (es un requisito técnico
// de Chrome/Android). A propósito NO cachea ni intercepta
// ninguna petición: todo sigue yendo siempre a la red, igual
// que si fuera una pestaña normal del navegador. Esto es
// intencional — el sistema maneja datos clínicos, y no
// queremos correr el riesgo de que alguna vez se muestre
// información vieja guardada en caché por error.
// ============================================================

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// No hay listener de "fetch": todas las peticiones siguen su
// camino normal hacia la red, sin pasar por este service worker.
