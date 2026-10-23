(() => {
    const toggle = document.querySelector('[data-password-toggle]');
    const password = document.getElementById('password');
    if (toggle && password) {
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const showing = password.type === 'password';
            password.type = showing ? 'text' : 'password';
            toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', String(showing));
        });
    }
    const form = document.getElementById('login-form');
    const reset = () => {
        if (!form) return;
        const button = form.querySelector('[type="submit"]');
        button.disabled = false;
        form.removeAttribute('aria-busy');
        button.querySelector('[data-submit-label]').textContent = 'Sign in to workspace';
        button.querySelector('[data-submit-arrow]').textContent = '↗';
    };
    if (form) form.addEventListener('submit', () => {
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        button.querySelector('[data-submit-label]').textContent = 'Signing you in…';
        button.querySelector('[data-submit-arrow]').textContent = '…';
    });
    window.addEventListener('pageshow', reset);
})();
