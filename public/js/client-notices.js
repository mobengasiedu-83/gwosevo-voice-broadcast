(() => {
    const bell = document.querySelector('[data-notification-bell]');
    if (!bell) return;
    let stopped = false;
    const refresh = async () => {
        if (document.hidden || stopped) return;
        try {
            const response = await fetch(bell.dataset.countUrl, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
            if (response.status === 401 || response.status === 403) { stopped = true; return; }
            if (!response.ok) return;
            const data = await response.json();
            if (!Number.isSafeInteger(data.unread) || data.unread < 0) return;
            bell.querySelector('[data-unread-count]').textContent = data.unread;
            bell.setAttribute('aria-label', `Notifications: ${data.unread} unread`);
        } catch (_) { /* Keep the last known count when the network is unavailable. */ }
    };
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', refresh);
})();
