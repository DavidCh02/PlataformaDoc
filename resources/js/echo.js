import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

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