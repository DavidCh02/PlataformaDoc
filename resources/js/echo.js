import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// La conexión de broadcasting es opcional (Reverb no siempre está corriendo).
// No se abre al arrancar para no generar errores de WebSocket; las vistas que
// quieran eventos en tiempo real la activan bajo demanda con enableBroadcasting().
window.enableBroadcasting = () => {
    if (window.Echo) return window.Echo;

    const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
    const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

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
            const scheme = import.meta.env.VITE_REVERB_SCHEME || 'https';
            const port = import.meta.env.VITE_REVERB_PORT || (scheme === 'https' ? 443 : 80);
            const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;

            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: reverbKey,
                wsHost: host,
                wsPort: port,
                wssPort: port,
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
            });
        }
    } catch (error) {
        console.warn('Broadcasting no disponible:', error);
    }

    return window.Echo;
};