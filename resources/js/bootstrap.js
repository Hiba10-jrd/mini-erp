import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;
let subscribedUser = null;
const received = new Set();

function syncNotificationChannel() {
    const user = document.querySelector('[data-notification-user]')?.dataset.notificationUser;
    if (subscribedUser && subscribedUser !== user) {
        window.Echo?.leave(`App.Models.User.${subscribedUser}`);
        subscribedUser = null;
        received.clear();
    }
    if (!user || user === subscribedUser || !import.meta.env.VITE_REVERB_APP_KEY) return;
    window.Echo ??= new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
    });
    subscribedUser = user;
    window.Echo.private(`App.Models.User.${user}`).notification((notification) => {
        const id = notification.notification_id || notification.id;
        if (!id || received.has(id)) return;
        received.add(id);
        if (received.size > 500) received.delete(received.values().next().value);
        window.Livewire?.dispatch('notification-received');
    });
    window.Echo.connector.pusher.connection.bind('connected', () => {
        window.Livewire?.dispatch('notification-received');
    });
}

document.addEventListener('DOMContentLoaded', syncNotificationChannel);
document.addEventListener('livewire:navigated', syncNotificationChannel);
