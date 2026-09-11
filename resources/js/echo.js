import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// La conexión de broadcasting es opcional (Reverb no siempre está corriendo).
// No se abre al arrancar para no generar errores de WebSocket; las vistas que
// quieran eventos en tiempo real la activan bajo demanda con enableBroadcasting().
// La config se lee de los <meta> (tiempo de ejecución) con fallback al build.
const metaContent = name => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';

window.enableBroadcasting = () => {
    if (window.Echo) return window.Echo;

    const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
    const reverbKey = metaContent('reverb-key') || import.meta.env.VITE_REVERB_APP_KEY;

    try {
        if (pusherKey) {
            window.Echo = new Echo({
                broadcaster: 'pusher',
                key: pusherKey,
                cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
                forceTLS: true,
                enabledTransports: ['ws', 'wss'],
            });
        } else if (reverbKey) {
            const defaultScheme = window.location.protocol === 'https:' ? 'https' : 'http';
            const scheme = metaContent('reverb-scheme') || import.meta.env.VITE_REVERB_SCHEME || defaultScheme;
            const port = metaContent('reverb-port') || import.meta.env.VITE_REVERB_PORT || (scheme === 'https' ? 443 : 80);
            const host = metaContent('reverb-host') || import.meta.env.VITE_REVERB_HOST || window.location.hostname;

            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: reverbKey,
                wsHost: host,
                wsPort: port,
                wssPort: port,
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
            });
            console.info(`[realtime] Conectando a Reverb en ${host}:${port} (${scheme}).`);
        } else {
            console.warn('[realtime] Desactivado: falta VITE_REVERB_APP_KEY (o REVERB_APP_KEY).');
        }
    } catch (error) {
        console.warn('Broadcasting no disponible:', error);
    }

    return window.Echo;
};